<?php
 declare(strict_types=1);
 header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: no-store');
 header('X-Content-Type-Options: nosniff');
 function respond(int $code, bool $success, string $message): void {
     http_response_code($code);
     echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
     exit;
 }
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, false, 'Please submit the contact form.');
 if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 25000) respond(413, false, 'Your request is too large.');
 $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
 $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
 if ($origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST)) !== $host) respond(403, false, 'Please submit your request from this website.');
 $raw = @file_get_contents(__DIR__ . '/config/config.js');
 if ($raw === false || !preg_match('/^\s*window\.SITE_CONFIG\s*=\s*(\{.*\})\s*;?\s*$/s', $raw, $m)) respond(503, false, 'The contact service is not configured.');
 $config = json_decode($m[1], true);
 if (!is_array($config) || empty($config['form']['enabled'])) respond(503, false, 'The contact service is not available.');
 $recipient = (string)($config['email'] ?? '');
 if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/(?:\.example|\.invalid|@example\.(?:com|org|net))$/i', $recipient)) respond(503, false, 'The contact mailbox has not been configured yet. Please try again later.');
 function field(string $key): string {
     $v = $_POST[$key] ?? '';
     if (!is_string($v)) respond(422, false, 'Please check your form fields.');
     return trim($v);
 }
 $name = field('name'); $email = field('email'); $service = field('service'); $message = field('message');
 if (field('website') !== '') respond(422, false, 'Unable to process this request.');
 if (strlen($name) < 2 || strlen($name) > 200 || preg_match('/[\r\n\x00]/', $name)) respond(422, false, 'Please enter your name.');
 if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || preg_match('/[\r\n]/', $email)) respond(422, false, 'Please enter a valid email address.');
 if ($service === '' || strlen($service) > 200 || preg_match('/[\r\n\x00]/', $service)) respond(422, false, 'Please select an enquiry topic.');
 if (strlen($message) < 10 || strlen($message) > 20000) respond(422, false, 'Please enter a message between 10 and 5,000 characters.');
 if (field('consent') !== 'yes') respond(422, false, 'Please confirm that you have read the Privacy Policy.');
 $topics = $config['form']['topics'] ?? [];
 if ($topics && !in_array($service, $topics, true)) respond(422, false, 'Please select an available enquiry topic.');
 $window = max(60, min(86400, (int)($config['form']['rateLimit']['windowSeconds'] ?? 900)));
 $limit = max(1, min(50, (int)($config['form']['rateLimit']['requests'] ?? 5)));
 $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
 $dir = sys_get_temp_dir() . '/loomelle-' . substr(hash('sha256', __DIR__), 0, 12);
 if (!is_dir($dir) && !@mkdir($dir, 0700, true)) respond(503, false, 'Please try again later.');
 $path = $dir . '/' . hash('sha256', $ip) . '.json';
 $lock = @fopen($path, 'c+');
 if (!$lock || !flock($lock, LOCK_EX)) respond(503, false, 'Please try again later.');
 $attempts = json_decode(stream_get_contents($lock), true) ?: [];
 $attempts = array_values(array_filter($attempts, static fn($t) => is_int($t) && $t > time() - $window));
 if (count($attempts) >= $limit) { flock($lock, LOCK_UN); fclose($lock); respond(429, false, 'Too many requests. Please try again later.'); }
 $attempts[] = time(); rewind($lock); ftruncate($lock, 0); fwrite($lock, json_encode($attempts)); fflush($lock); flock($lock, LOCK_UN); fclose($lock);
 $company = preg_replace('/[\r\n]/', '', (string)($config['company'] ?? 'Website'));
 $subject = '=?UTF-8?B?' . base64_encode((string)($config['form']['subjectPrefix'] ?? $company) . ' — ' . $service) . '?=';
 $body = "New website enquiry\n\nName: {$name}\nEmail: {$email}\nTopic: {$service}\n\nMessage:\n{$message}\n\nPrivacy consent: Yes\nReceived: " . gmdate('c');
 $headers = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit', 'From: ' . $recipient, 'Reply-To: ' . $email];
 if (!function_exists('mail')) respond(503, false, 'The server mail service is not available. Please contact us by email.');
 $sent = @mail($recipient, $subject, $body, implode("\r\n", $headers));
 if (!$sent) respond(502, false, 'Your request could not be sent. Please try again later or contact us by email.');
 respond(200, true, (string)($config['form']['successMessage'] ?? 'Thank you! Your request has been received.'));
