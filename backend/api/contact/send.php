<?php
/**
 * POST /backend/api/contact/send.php
 * Body: { name, email, subject, message }
 *
 * Validates and stores a contact form submission in the DB.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body    = getJsonBody();
$name    = trim($body['name']    ?? '');
$email   = strtolower(trim($body['email']   ?? ''));
$subject = trim($body['subject'] ?? '');
$message = trim($body['message'] ?? '');

if ($name === '') {
    jsonError('Name is required.');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('A valid email address is required.');
}
if ($subject === '') {
    jsonError('Subject is required.');
}
if (mb_strlen($message) < 10) {
    jsonError('Message must be at least 10 characters.');
}
if (mb_strlen($message) > 2000) {
    jsonError('Message is too long (maximum 2000 characters).');
}

try {
    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO contact_messages (sender_name, sender_email, subject, message)
         VALUES (:name, :email, :subject, :message)'
    );
    $stmt->execute([
        'name'    => $name,
        'email'   => $email,
        'subject' => $subject,
        'message' => $message,
    ]);

    jsonOk([], 'Message received. We will get back to you within 24 hours.');

} catch (PDOException $e) {
    jsonError('Database error. Please try again.', 500);
}
