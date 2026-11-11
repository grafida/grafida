<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Secret;

/**
 * Windows DPAPI (Data Protection API) via a direct FFI call into crypt32.dll.
 *
 * {@see WindowsSecretStore} originally shelled out to a whole `powershell.exe`
 * per protect/unprotect. PowerShell cold-starts in roughly a second and, because
 * the worker answers one request at a time, every request behind that call
 * waited for it (under Boson, whose kernel ran on the UI thread, the whole window
 * froze) — and it ran on every request that needed a stored secret (site token,
 * AI key). DPAPI
 * is just a pair of native Win32 functions, so calling them directly is
 * sub-millisecond and spawns nothing.
 *
 * The bytes are byte-for-byte compatible with the PowerShell/.NET
 * `ProtectedData` path: both call the same OS primitive with the CurrentUser key
 * and no additional entropy, so blobs written by one are readable by the other.
 * That keeps already-stored secrets working and lets {@see WindowsSecretStore}
 * fall back to PowerShell whenever this is unavailable.
 */
final class WindowsDpapi
{
    /** Never show an interactive prompt (matches .NET ProtectedData). */
    private const CRYPTPROTECT_UI_FORBIDDEN = 0x1;

    private static ?\FFI $crypt32 = null;
    private static ?\FFI $kernel32 = null;
    private static ?bool $available = null;

    /** Whether native DPAPI can be used on this host right now. */
    public function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (\PHP_OS_FAMILY !== 'Windows' || !\extension_loaded('ffi')) {
            return self::$available = false;
        }

        try {
            self::$crypt32 = \FFI::cdef(
                'typedef struct { uint32_t cbData; unsigned char *pbData; } GRAFIDA_DATA_BLOB;'
                . 'int CryptProtectData(GRAFIDA_DATA_BLOB *pDataIn, void *szDataDescr,'
                . ' GRAFIDA_DATA_BLOB *pOptionalEntropy, void *pvReserved, void *pPromptStruct,'
                . ' uint32_t dwFlags, GRAFIDA_DATA_BLOB *pDataOut);'
                . 'int CryptUnprotectData(GRAFIDA_DATA_BLOB *pDataIn, void *ppszDataDescr,'
                . ' GRAFIDA_DATA_BLOB *pOptionalEntropy, void *pvReserved, void *pPromptStruct,'
                . ' uint32_t dwFlags, GRAFIDA_DATA_BLOB *pDataOut);',
                'crypt32.dll'
            );
            // DPAPI allocates the output buffer with LocalAlloc; we must LocalFree it.
            self::$kernel32 = \FFI::cdef('void *LocalFree(void *hMem);', 'kernel32.dll');
        } catch (\Throwable) {
            self::$crypt32  = null;
            self::$kernel32 = null;

            return self::$available = false;
        }

        return self::$available = true;
    }

    /**
     * DPAPI-encrypt for the current user.
     *
     * @return string|null the raw ciphertext blob, or null if unavailable/failed
     */
    public function protect(string $plaintext): ?string
    {
        return $this->call(true, $plaintext);
    }

    /**
     * DPAPI-decrypt for the current user.
     *
     * @return string|null the plaintext, or null if unavailable/failed
     */
    public function unprotect(string $blob): ?string
    {
        return $this->call(false, $blob);
    }

    /**
     * Runs one CryptProtectData/CryptUnprotectData call. Any FFI issue returns
     * null so the caller can fall back to the PowerShell path.
     *
     * @param bool $encrypt true → CryptProtectData, false → CryptUnprotectData
     */
    private function call(bool $encrypt, string $data): ?string
    {
        if (!$this->available() || self::$crypt32 === null || self::$kernel32 === null) {
            return null;
        }

        $crypt32  = self::$crypt32;
        $kernel32 = self::$kernel32;

        try {
            $length = \strlen($data);

            $in  = $crypt32->new('GRAFIDA_DATA_BLOB');
            $out = $crypt32->new('GRAFIDA_DATA_BLOB');

            // Owned buffer (auto-freed with $inBuffer); at least 1 byte so the
            // pointer is always valid. DPAPI tolerates cbData == 0.
            $inBuffer = $crypt32->new('unsigned char[' . \max(1, $length) . ']');

            if ($length > 0) {
                \FFI::memcpy($inBuffer, $data, $length);
            }

            $in->cbData = $length;
            $in->pbData = $crypt32->cast('unsigned char *', \FFI::addr($inBuffer));

            $ok = $encrypt
                ? $crypt32->CryptProtectData(
                    \FFI::addr($in),
                    null,
                    null,
                    null,
                    null,
                    self::CRYPTPROTECT_UI_FORBIDDEN,
                    \FFI::addr($out)
                )
                : $crypt32->CryptUnprotectData(
                    \FFI::addr($in),
                    null,
                    null,
                    null,
                    null,
                    self::CRYPTPROTECT_UI_FORBIDDEN,
                    \FFI::addr($out)
                );

            if ($ok === 0 || \FFI::isNull($out->pbData)) {
                if (!\FFI::isNull($out->pbData)) {
                    $kernel32->LocalFree($out->pbData);
                }

                return null;
            }

            $result = \FFI::string($out->pbData, $out->cbData);
            $kernel32->LocalFree($out->pbData);

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }
}
