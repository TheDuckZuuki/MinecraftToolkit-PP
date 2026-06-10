<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use Illuminate\Support\Facades\Log;

class CurseForgeApiKeyProvider
{
    /**
     * Deprecated emergency/private-build key slot.
     *
     * Public builds must keep this empty. For public distribution use the Vercel/Toolkit
     * proxy instead, because CurseForge API keys must not be shipped to third parties.
     *
     * Encoding format for each release key:
     *   str_split(strrev(base64_encode(str_rot13($plainApiKey))), 18)
     *
     * If this array is empty and no env override exists, CurseForge is automatically disabled.
     *
     * @var array<int, string>
     */
    private const EMBEDDED_OBFUSCATED_API_KEY_PARTS = [];

    public function hasKey(): bool
    {
        return $this->getKey() !== null;
    }

    public function source(): ?string
    {
        if ($this->envKey() !== null) {
            return 'local-env';
        }

        if ($this->embeddedKey() !== null) {
            return 'embedded-private-build-key';
        }

        return null;
    }

    public function getKey(): ?string
    {
        return $this->envKey() ?? $this->embeddedKey();
    }

    private function envKey(): ?string
    {
        $key = trim((string) config('minecrafttoolkit.curseforge_api_key', ''));

        return $key !== '' ? $key : null;
    }

    private function embeddedKey(): ?string
    {
        if (self::EMBEDDED_OBFUSCATED_API_KEY_PARTS === []) {
            return null;
        }

        try {
            $encoded = strrev(implode('', self::EMBEDDED_OBFUSCATED_API_KEY_PARTS));
            $decoded = base64_decode($encoded, true);
            if (!is_string($decoded) || $decoded === '') {
                return null;
            }

            $key = trim(str_rot13($decoded));

            return $key !== '' ? $key : null;
        } catch (\Throwable $exception) {
            Log::warning('Minecraft Toolkit embedded CurseForge API key could not be decoded.', [
                'exception' => $exception::class,
            ]);

            return null;
        }
    }
}
