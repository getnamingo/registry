<?php
/**
 * Argora Foundry
 *
 * A modular PHP boilerplate for building SaaS applications, admin panels, and control systems.
 *
 * @package    App
 * @author     Taras Kondratyuk <help@argora.org>
 * @copyright  Copyright (c) 2025 Argora
 * @license    MIT License
 * @link       https://github.com/getargora/foundry
 */

declare(strict_types=1);

namespace App\Lib;

final class Redirect
{
    private ?string $name;
    private int $status;
    private bool $sent = false;

    public function __construct(?string $name = null, int $status = 301)
    {
        $this->name   = $this->sanitizeUrl($name);
        $this->status = $this->normalizeStatus($status);
    }

    public function __destruct()
    {
        if (!$this->sent) {
            $this->redirect();
        }
    }

    public function to(string $name, int $status = 301): self
    {
        $this->name   = $this->sanitizeUrl($name);
        $this->status = $this->normalizeStatus($status);
        return $this;
    }

    public function route(string $name, array $params1 = [], array $params2 = [], int $status = 301): self
    {
        if (function_exists('route')) {
            try {
                $url = route($name, $params1, $params2);
                $this->name = $this->sanitizeUrl($url);
            } catch (\Throwable $e) {
                $this->name = $this->name ?? '/';
            }
        } else {
            $this->name = $this->sanitizeUrl($name);
        }

        $this->status = $this->normalizeStatus($status);
        return $this;
    }

    public function with(string $type, string $message): self
    {
        if (function_exists('flash')) {
            $type    = trim($type);
            $message = trim($message);
            if ($type !== '' && $message !== '') {
                flash($type, $message);
            }
        }
        return $this;
    }

    public function redirect(): void
    {
        if ($this->sent) {
            return;
        }
        $this->sent = true;

        $target = $this->name ?: '/';
        $target = $this->sanitizeUrl($target);

        if (!$this->isSafeUrl($target)) {
            $target = '/';
        }

        if (!headers_sent()) {
            $safe = $this->stripCtl($target);
            header('Location: ' . $safe, true, $this->status);
            exit;
        }

        // Headers already sent — render a minimal safe fallback
        $safeHtml = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!doctype html><meta http-equiv="refresh" content="0;url=' . $safeHtml . '">'
           . '<script>location.replace(' . json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>'
           . '<title>Redirecting…</title>'
           . '<p>If you are not redirected, <a href="' . $safeHtml . '">click here</a>.</p>';
        exit;
    }

    /** Allow only 300–399; normalize unexpected values to 302 (safer default than 301). */
    private function normalizeStatus(int $status): int
    {
        return ($status >= 300 && $status <= 399) ? $status : 302;
    }

    /**
     * Basic URL sanitation:
     * - Trim
     * - Reject control chars
     * - Allow relative URLs or same-origin absolute URLs
     * - Reject javascript:, data:, etc.
     */
    private function sanitizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $url = $this->stripCtl($url);

        // Quick scheme check
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:')) {
            return '/';
        }

        // parse_url tolerates relative paths (scheme/host missing)
        $parts = @parse_url($url);
        if ($parts === false) {
            return '/';
        }

        // If absolute URL, allow only same host (mitigates open redirect)
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $sameHost = isset($parts['host']) && strcasecmp($parts['host'], $host) === 0;
            $scheme   = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
            $httpsOk  = in_array($scheme, ['http', 'https'], true);

            if (!$httpsOk || !$sameHost) {
                return '/';
            }
        }

        return $url;
    }

    private function isSafeUrl(string $url): bool
    {
        $parts = @parse_url($url);
        if ($parts === false) {
            return false;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $host   = $_SERVER['HTTP_HOST'] ?? '';
            $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
            $same   = isset($parts['host']) && strcasecmp($parts['host'], $host) === 0;
            if (!in_array($scheme, ['http', 'https'], true) || !$same) {
                return false;
            }
        }
        // Disallow CR/LF and NUL
        return $url === $this->stripCtl($url);
    }

    /** Strip CR, LF, and other control chars to prevent header injection. */
    private function stripCtl(string $s): string
    {
        // Remove ASCII control chars except TAB (0x09)
        return preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/u', '', $s) ?? '';
    }
}