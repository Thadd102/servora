<?php
require_once "../includes/client_auth.php";

// Paystack sends the customer here when they press Cancel on checkout.
// Do not change financial state from a browser redirect: a late webhook may still
// report the authoritative result. This page is UX only.
header('Location: wallet.php?payment=cancelled');
exit;
