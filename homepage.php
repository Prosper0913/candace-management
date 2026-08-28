
<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<h1 class="headline"
    id="typing-container"
    data-text="Welcome back, <?= h($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>">
    <span id="typed-headline" aria-hidden="true"></span>
</h1>
<div style="text-align: center;">
        <p class="subhead">Today is,  <?= date('F j, Y') ?>.</p>
    <div class="button-group">
        <a href="income.php" class="btn-ghost btn" style="text-decoration:none;">+ Add sale</a>
        <a href="expenses.php" class="btn" style="text-decoration:none;">+ Add expense</a>
    </div>
</div>
    <div class="button-group" style="padding-top:100px;">
<a href="index.php" class="btn-dashboard" style="text-decoration:none;">Go to Dashboard</a>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", () => {
      const container = document.getElementById("typing-container");
      const target = document.getElementById("typed-headline");
      
      const textToType = container.getAttribute("data-text") || ""; 
      
      let index = 0;
      const speed = 75;

      function typeWriter() {
        if (index < textToType.length) {
          target.textContent += textToType.charAt(index);
          index++;
          setTimeout(typeWriter, speed);
        }
      }

      typeWriter();
    });
    </script>

