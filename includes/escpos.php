<?php
/**
 * Minimal ESC/POS builder.
 *
 * ESC/POS is the command language almost every thermal receipt printer speaks
 * (Epson invented it; every cheap clone printer copies it). It's just plain
 * text with a few special control-byte sequences mixed in for bold, centering,
 * cutting the paper, etc. There's no driver involved - we build a raw byte
 * string and hand it to the printer directly.
 */
class EscposReceipt
{
    const ESC = "\x1B";
    const GS  = "\x1D";

    private string $buffer = '';
    private int $width;

    public function __construct(int $charsPerLine = 32)
    {
        $this->width = $charsPerLine;
        $this->buffer .= self::ESC . '@'; // initialize printer (reset formatting)
    }

    public function centerAlign(): self
    {
        $this->buffer .= self::ESC . 'a' . chr(1);
        return $this;
    }

    public function leftAlign(): self
    {
        $this->buffer .= self::ESC . 'a' . chr(0);
        return $this;
    }

    public function bold(bool $on = true): self
    {
        $this->buffer .= self::ESC . 'E' . chr($on ? 1 : 0);
        return $this;
    }

    /** Double-height, double-width text - used for the store name / total. */
    public function bigText(bool $on = true): self
    {
        $this->buffer .= self::GS . '!' . chr($on ? 0x11 : 0x00);
        return $this;
    }

    public function text(string $line): self
    {
        $this->buffer .= $line;
        return $this;
    }

    public function newline(int $times = 1): self
    {
        $this->buffer .= str_repeat("\n", $times);
        return $this;
    }

    /** A full-width dashed divider line. */
    public function rule(string $char = '-'): self
    {
        $this->buffer .= str_repeat($char, $this->width) . "\n";
        return $this;
    }

    /** Left-justified label on the left, value on the right, same line. */
    public function twoColumn(string $left, string $right): self
    {
        $space = $this->width - strlen($left) - strlen($right);
        if ($space < 1) {
            // Line too long for the paper width - wrap the label onto its own line.
            $this->buffer .= $left . "\n" . str_pad($right, $this->width, ' ', STR_PAD_LEFT) . "\n";
        } else {
            $this->buffer .= $left . str_repeat(' ', $space) . $right . "\n";
        }
        return $this;
    }

    /** Feeds paper forward then cuts it (most printers: partial cut, leaves a tab). */
    public function cut(): self
    {
        $this->buffer .= "\n\n\n";
        $this->buffer .= self::GS . 'V' . chr(1);
        return $this;
    }

    public function getBytes(): string
    {
        return $this->buffer;
    }
}

/**
 * Builds the actual receipt content for a sale + its line items.
 * $sale is a row from the `sales` table, $items is an array of rows from `sale_items`.
 */
function build_receipt_escpos(array $sale, array $items): string
{
    $r = new EscposReceipt(PRINTER_PAPER_WIDTH);

    $r->centerAlign()->bigText(true)->text(STORE_NAME)->bigText(false)->newline();
    if (defined('STORE_ADDRESS') && STORE_ADDRESS) {
        $r->text(STORE_ADDRESS)->newline();
    }
    $r->newline();

    $r->leftAlign();
    $r->text('Receipt #' . str_pad((string) $sale['id'], 6, '0', STR_PAD_LEFT))->newline();
    $r->text(date('M j, Y g:i A', strtotime($sale['created_at'])))->newline();
    $r->rule();

    foreach ($items as $item) {
        $r->text($item['name'])->newline();
        $qtyLine = number_format((float) $item['unit_price'], 2) . ' x ' . (int) $item['quantity'];
        $r->twoColumn($qtyLine, number_format((float) $item['line_total'], 2));
    }

    $r->rule();
    $r->bold(true);
    $r->twoColumn('TOTAL', 'P ' . number_format((float) $sale['total_amount'], 2));
    $r->bold(false);
    $r->newline();

    $r->centerAlign();
    $r->text('Thank you for shopping with us!')->newline();
    $r->cut();

    return $r->getBytes();
}