<?php

namespace App\Security\Network;

final class CidrMatcher
{
    public function valid(string $cidr): bool
    {
        return $this->parts($cidr) !== null;
    }

    public function contains(string $cidr, string $address): bool
    {
        $parts = $this->parts($cidr);
        $packedAddress = @inet_pton($address);
        if ($parts === null || $packedAddress === false || strlen($parts['network']) !== strlen($packedAddress)) {
            return false;
        }

        $wholeBytes = intdiv($parts['prefix'], 8);
        $remainingBits = $parts['prefix'] % 8;
        if ($wholeBytes > 0
            && substr($parts['network'], 0, $wholeBytes) !== substr($packedAddress, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($parts['network'][$wholeBytes]) & $mask) === (ord($packedAddress[$wholeBytes]) & $mask);
    }

    /** @return array{network: string, prefix: int}|null */
    private function parts(string $cidr): ?array
    {
        if (substr_count($cidr, '/') !== 1) {
            return null;
        }
        [$address, $prefixText] = explode('/', $cidr, 2);
        if ($prefixText === '' || ! ctype_digit($prefixText)) {
            return null;
        }
        $network = @inet_pton($address);
        if ($network === false) {
            return null;
        }
        $prefix = (int) $prefixText;
        $max = strlen($network) === 4 ? 32 : 128;
        if ($prefix < 0 || $prefix > $max) {
            return null;
        }

        return ['network' => $network, 'prefix' => $prefix];
    }
}
