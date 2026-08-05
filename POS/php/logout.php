<?php
session_start();
session_destroy();
header('Location: ../../Landing Page/php/login.php');
exit;
