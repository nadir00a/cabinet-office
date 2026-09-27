<?php
require_once __DIR__ . '/config/config.php';
redirect(current_user() ? home_page_for_role(current_user()['role_code']) : '/login.php');
