<?php

namespace App\Services;

/**
 * Encrypts / decrypts API keys using Laravel's built-in AES-256-CBC
 * cipher tied to APP_KEY. Keys are NEVER returned to the frontend.
 */
class ApiKeyEncryption
{
    /**
     * Encrypt an API key for storage.
     * Uses Laravel's encrypt() which wraps AES-256-CBC + HMAC-SHA256.
     */
    public static function encrypt(string $apiKey): string
    {
        return encrypt($apiKey);
    }

    /**
     * Decrypt a stored API key for server-side use ONLY.
     * Never pass the result to any HTTP response.
     */
    public static function decrypt(string $encrypted): string
    {
        return decrypt($encrypted);
    }

    /**
     * Return a safe hint showing only the last 6 characters.
     * e.g. "sk-ant-api03-..." → "...c123xy"
     */
    public static function hint(string $apiKey): string
    {
        return '...' . substr($apiKey, -6);
    }

    /**
     * Return a masked version for display purposes.
     * e.g. "sk-ant-" + 20 asterisks + last 6 chars
     */
    public static function mask(string $apiKey): string
    {
        $prefix = substr($apiKey, 0, 6);
        $suffix = substr($apiKey, -6);
        return $prefix . str_repeat('*', 20) . $suffix;
    }
}
