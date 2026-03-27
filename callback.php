<?php
/**
 * Root callback endpoint for M-Pesa (ngrok-friendly path).
 * Delegates processing to the existing payment callback handler.
 */

require_once __DIR__ . '/backend/api/payments/mpesa_callback.php';
