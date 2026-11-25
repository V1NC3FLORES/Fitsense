<?php
/**
 * Shared error handling utilities for user-friendly messages and logging.
 */

if (!function_exists('app_log_error')) {
    function app_log_error(string $message, array $context = []): void
    {
        $prefix = '[GymApp]';
        if (!empty($context)) {
            $contextText = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($contextText !== false) {
                error_log($prefix . ' ' . $message . ' ' . $contextText);
                return;
            }
        }

        error_log($prefix . ' ' . $message);
    }
}

if (!function_exists('app_safe_error_message')) {
    function app_safe_error_message(string $fallback, string $type = 'general'): string
    {
        $messageMap = [
            'db' => 'We could not process your request right now. Please try again later.',
            'email' => 'We could not send the verification email right now. Please try again later.',
            'auth' => 'Your session has expired or the request is invalid. Please try again.',
            'payment' => 'The payment service is temporarily unavailable. Please try again later.',
            'validation' => 'Please review the information and try again.',
        ];

        return $messageMap[$type] ?? $fallback;
    }
}
