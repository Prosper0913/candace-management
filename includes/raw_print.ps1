param(
    [Parameter(Mandatory=$true)][string]$Name,
    [Parameter(Mandatory=$true)][string]$Path
)

$code = @"
using System;
using System.Runtime.InteropServices;

public class RawPrinter {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    public class DOCINFOA {
        [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPStr)] public string pDataType;
    }

    [DllImport("winspool.Drv", EntryPoint = "OpenPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true)]
    public static extern bool OpenPrinter(string szPrinter, out IntPtr hPrinter, IntPtr pd);

    [DllImport("winspool.Drv", EntryPoint = "ClosePrinter", SetLastError = true, ExactSpelling = true)]
    public static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "StartDocPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true)]
    public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFOA di);

    [DllImport("winspool.Drv", EntryPoint = "EndDocPrinter", SetLastError = true, ExactSpelling = true)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "StartPagePrinter", SetLastError = true, ExactSpelling = true)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "EndPagePrinter", SetLastError = true, ExactSpelling = true)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "WritePrinter", SetLastError = true, ExactSpelling = true)]
    public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

    public static string Send(string printer, byte[] data) {
        IntPtr h;
        if (!OpenPrinter(printer, out h, IntPtr.Zero))
            return "OpenPrinter failed (error " + Marshal.GetLastWin32Error() + "). Check the printer name.";
        DOCINFOA di = new DOCINFOA();
        di.pDocName = "POS Receipt";
        di.pDataType = "RAW";
        string err = null;
        if (StartDocPrinter(h, 1, di)) {
            if (StartPagePrinter(h)) {
                IntPtr p = Marshal.AllocCoTaskMem(data.Length);
                Marshal.Copy(data, 0, p, data.Length);
                int written;
                if (!WritePrinter(h, p, data.Length, out written))
                    err = "WritePrinter failed (error " + Marshal.GetLastWin32Error() + ")";
                Marshal.FreeCoTaskMem(p);
                EndPagePrinter(h);
            } else err = "StartPagePrinter failed";
            EndDocPrinter(h);
        } else err = "StartDocPrinter failed (error " + Marshal.GetLastWin32Error() + ")";
        ClosePrinter(h);
        return err;
    }
}
"@

Add-Type -TypeDefinition $code
$bytes = [System.IO.File]::ReadAllBytes($Path)
$result = [RawPrinter]::Send($Name, $bytes)
if ($result) { Write-Output $result; exit 1 }
exit 0