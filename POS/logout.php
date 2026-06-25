<?php
session_start();
session_destroy();
header('Location: ../Landing Page/login.php');
exit;
