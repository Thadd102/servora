<?php

require_once __DIR__ . "/auth.php";

// User must be logged in first
requireLogin();

// Only admin and super_admin can access the admin area
if (!in_array($_SESSION['role'], ['admin', 'super_admin'], true)) {

    http_response_code(403);
    die("Access denied.");

}