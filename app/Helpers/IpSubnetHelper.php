<?php

namespace App\Helpers;

class IpSubnetHelper
{
    /**
     * Check if an IP address is within a subnet
     * 
     * @param string $ip The IP address to check
     * @param string $subnet The subnet in CIDR notation (e.g., 192.168.1.0/24 or 2001:db8::/64)
     * @return bool
     */
    public static function isIpInSubnet(string $ip, string $subnet): bool
    {
        if (empty($ip) || empty($subnet)) {
            return false;
        }

        // Check if subnet contains a slash for CIDR notation
        if (!str_contains($subnet, '/')) {
            return false;
        }

        [$subnetAddress, $prefixLength] = explode('/', $subnet, 2);
        $prefixLength = (int) $prefixLength;

        // Determine if we're dealing with IPv4 or IPv6
        $isIpv6 = str_contains($ip, ':');
        $isSubnetIpv6 = str_contains($subnetAddress, ':');

        // IP and subnet must be same type
        if ($isIpv6 !== $isSubnetIpv6) {
            return false;
        }

        if ($isIpv6) {
            return self::isIpv6InSubnet($ip, $subnetAddress, $prefixLength);
        } else {
            return self::isIpv4InSubnet($ip, $subnetAddress, $prefixLength);
        }
    }

    /**
     * Check if an IPv4 address is within a subnet
     */
    private static function isIpv4InSubnet(string $ip, string $subnetAddress, int $prefixLength): bool
    {
        // Validate IP addresses
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || 
            !filter_var($subnetAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // Validate prefix length for IPv4
        if ($prefixLength < 0 || $prefixLength > 32) {
            return false;
        }

        // Convert IP addresses to binary
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnetAddress);

        // Calculate subnet mask
        $mask = -1 << (32 - $prefixLength);

        // Check if IP is in subnet
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Check if an IPv6 address is within a subnet
     */
    private static function isIpv6InSubnet(string $ip, string $subnetAddress, int $prefixLength): bool
    {
        // Validate IP addresses
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || 
            !filter_var($subnetAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        // Validate prefix length for IPv6
        if ($prefixLength < 0 || $prefixLength > 128) {
            return false;
        }

        // Convert to binary representation
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnetAddress);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        // Compare the bits up to the prefix length
        $bytesToCompare = intdiv($prefixLength, 8);
        $bitsRemaining = $prefixLength % 8;

        // Compare full bytes
        for ($i = 0; $i < $bytesToCompare; $i++) {
            if ($ipBinary[$i] !== $subnetBinary[$i]) {
                return false;
            }
        }

        // Compare remaining bits if any
        if ($bitsRemaining > 0 && $bytesToCompare < 16) {
            $mask = 0xFF << (8 - $bitsRemaining);
            $ipByte = ord($ipBinary[$bytesToCompare]);
            $subnetByte = ord($subnetBinary[$bytesToCompare]);
            
            if (($ipByte & $mask) !== ($subnetByte & $mask)) {
                return false;
            }
        }

        return true;
    }
}