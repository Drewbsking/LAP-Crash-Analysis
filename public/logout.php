<?php
require dirname(__DIR__) . '/app/bootstrap.php';
if (!is_post()) { http_response_code(405); header('Allow: POST'); exit('Use the Sign out button.'); }
check_csrf();
App\Auth::logout();
redirect('/login.php');
