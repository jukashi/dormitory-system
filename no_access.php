<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/layout.php';

$user = require_login();
page_start('No Assigned Access', $user);
?>
  <p class="eyebrow">Staff Access</p>
  <h1>No sections assigned</h1>
  <section class="panel"><p>Your account can sign in, but an administrator has not yet assigned any system sections to it.</p><p class="muted">Please ask an administrator to select your allowed access in Administrator Control.</p></section>
<?php page_end(); ?>
