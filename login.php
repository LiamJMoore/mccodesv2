<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 * 
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

global $set;
require_once('globals_nonauth.php');
$login_csrf = request_csrf_code('login');
print
        <<<EOF
<!DOCTYPE html>
<html lang="en">
<head>
<title>{$set['game_name']}</title>
<meta charset="iso-8859-1" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
<script type="text/javascript" src="js/login.js"></script>
<link href="css/game.css" type="text/css" rel="stylesheet" />
</head>
<body class="mc26 auth" onload="getme();">
<div class="auth-wrap">
EOF;
$IP = str_replace(['/', '\\', '\0'], '', $_SERVER['REMOTE_ADDR']);
if (file_exists('ipbans/' . $IP))
{
    die(
            "<span style='font-weight: bold; color:red;'>
            Your IP has been banned, there is no way around this.
            </span></body></html>");
}
$year = date('Y');
echo "<section class='auth-hero'>
<div class='brand'><span class='brand-mark'>" . strtoupper(substr((string)$set['game_name'], 0, 1)) . "</span><span class='brand-name'>{$set['game_name']}</span></div>
<h1>Build your name. Take the city.</h1>
<p class='auth-about'>" . nl2br($set['game_description']) . "</p>
<a class='auth-cta' href='register.php'>Create a free account &rarr;</a>
</section>";
echo <<<EOF
<section class="auth-card">
<h2>Log in</h2>
<form action='authenticate.php' method='POST' name='login' onsubmit='return saveme();'>
<label>Username<input type='text' name='username' autocomplete='username' /></label>
<label>Password<input type='password' name='password' autocomplete='current-password' /></label>
<div class='auth-remember'><span>Remember me?</span>
<label class='pill'><input type='radio' value='ON' name='save' /> Yes</label>
<label class='pill'><input type='radio' value='OFF' name='save' /> No</label></div>
<input type='hidden' name='verf' value='{$login_csrf}' />
<input type='submit' value='Log in'>
</form>
<p class='auth-alt'>New here? <a href='register.php'>Register now</a></p>
</section>
EOF;
echo "<footer class='auth-foot'>Powered by codes made by Dabomstew (&copy; {$year}). Game Copyright &copy; {$year} {$set['game_owner']}.</footer>";
print
        <<<OUT
</div>
</body>
</html>
OUT;
