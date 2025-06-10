<?php

/**
 * PHP Script to send an OSC (Open Sound Control) message for DMX level data via UDP.
 *
 * This script is tailored for Open Lighting Architecture (OLA) receivers,
 * sending a DMX channel number and its level to a specified universe.
 * It now supports command-line arguments for universe, channel, start level, end level, and fade duration.
 *
 * Usage: php osc.php <universe> <channel> <from_level> <to_level> <fade_duration_seconds>
 * Example: php osc.php 1 1 0 255 3  (Fades channel 1 from 0 to 255 over 3 seconds in Universe 1)
 * Example: php osc.php 2 5 200 50 2    (Fades channel 5 from 200 to 50 over 2 seconds in Universe 2)
 * Example: php osc.php 1 1 0 128 0  (Immediately sets channel 1 to 128 in Universe 1, starting from 0)
 */

// --- Configuration ---
$targetIp = '192.168.251.128'; // The IP address of the OSC receiver (OLA)
$targetPort = 7770;            // The port number for the OSC receiver

// --- Argument Parsing ---
if ($argc !== 6) { // Now expecting 6 arguments: script name + 5 parameters
    echo "Usage: php " . basename(__FILE__) . " <universe> <channel> <from_level> <to_level> <fade_duration_seconds>\n";
    echo "Example: php " . basename(__FILE__) . " 1 1 0 255 3  (Fades channel 1 from 0 to 255 over 3 seconds in Universe 1)\n";
    echo "Example: php " . basename(__FILE__) . " 2 5 200 50 2    (Fades channel 5 from 200 to 50 over 2 seconds in Universe 2)\n";
    echo "Example: php " . basename(__FILE__) . " 1 1 0 128 0  (Immediately sets channel 1 to 128 in Universe 1, starting from 0)\n";
    exit(1);
}

$dmxUniverse = (int)$argv[1]; // DMX Universe
$dmxChannel = (int)$argv[2];  // DMX Channel
$fromLevel = (int)$argv[3];   // Starting DMX Level
$toLevel = (int)$argv[4];     // Target DMX Level
$fadeDuration = (float)$argv[5]; // Duration in seconds

// Validate input
if ($dmxUniverse < 0) { // Universes are typically 0 or 1 upwards, 0 is common in OLA
    echo "Error: DMX Universe cannot be negative.\n";
    exit(1);
}
if ($dmxChannel < 1 || $dmxChannel > 512) {
    echo "Error: DMX Channel must be between 1 and 512.\n";
    exit(1);
}
if ($fromLevel < 0 || $fromLevel > 255) {
    echo "Error: 'From' DMX Level must be between 0 and 255.\n";
    exit(1);
}
if ($toLevel < 0 || $toLevel > 255) {
    echo "Error: 'To' DMX Level must be between 0 and 255.\n";
    exit(1);
}
if ($fadeDuration < 0) { // Duration can be 0, but not negative
    echo "Error: Fade duration cannot be negative.\n";
    exit(1);
}

// OSC message details for OLA DMX (now dynamic with universe)
$oscAddress = '/dmx/universe/' . $dmxUniverse; // The OSC address pattern for the specified Universe in OLA
$oscTypeTag = ',ii';            // Type tag: ',ii' for two integers (channel number, level)

// --- OSC Message Construction Functions ---

/**
 * Pads a string with null bytes to the next 4-byte boundary.
 * OSC strings must be null-terminated and padded to multiples of 4 bytes.
 *
 * @param string $str The input string.
 * @return string The null-terminated and padded string.
 */
function oscPadString(string $str): string {
    $str .= "\0"; // Null-terminate the string
    $paddingNeeded = 4 - (strlen($str) % 4);
    if ($paddingNeeded < 4) { // Only add padding if not already a multiple of 4
        $str .= str_repeat("\0", $paddingNeeded);
    }
    return $str;
}

/**
 * Converts an integer to a 32-bit big-endian binary string.
 * OSC integers are represented as 32-bit big-endian integers.
 *
 * @param int $int The integer to convert.
 * @return string The 32-bit big-endian binary string.
 */
function oscInt32(int $int): string {
    return pack("N", $int); // "N" for unsigned long (32-bit) in big-endian
}

// Note: oscFloat32 function is included but not used in this DMX context as DMX levels are integers.
/**
 * Converts a float to a 32-bit big-endian binary string.
 * OSC floats are represented as 32-bit big-endian floats.
 *
 * @param float $float The float to convert.
 * @return string The 32-bit big-endian binary string.
 */
function oscFloat32(float $float): string {
    // "G" for single-precision float (32-bit) in big-endian
    return pack("G", $float);
}

/**
 * Constructs and sends a single OSC DMX message.
 *
 * @param resource $socket The UDP socket resource.
 * @param string $ip The target IP address.
 * @param int $port The target port.
 * @param string $address The OSC address pattern.
 * @param string $typeTag The OSC type tag.
 * @param int $channel The DMX channel number.
 * @param int $level The DMX level.
 * @return bool True on success, false on failure.
 */
function sendOscDmxMessage($socket, string $ip, int $port, string $address, string $typeTag, int $channel, int $level): bool {
    $payload = '';

    // Add OSC Address Pattern
    $payload .= oscPadString($address);

    // Add Type Tag String
    $payload .= oscPadString($typeTag);

    // Add Arguments (channel and level)
    $payload .= oscInt32($channel);
    $payload .= oscInt32($level);

    $bytesSent = @socket_sendto($socket, $payload, strlen($payload), 0, $ip, $port);

    if ($bytesSent === false) {
        $errorCode = socket_last_error($socket);
        $errorMessage = socket_strerror($errorCode);
        error_log("Error sending data for Universe {$address}, Channel {$channel}, Level {$level}: [{$errorCode}] {$errorMessage}");
        return false;
    }
    return true;
}


// --- Main Script Logic ---

// 1. Create a UDP socket
// AF_INET for IPv4, SOCK_DGRAM for UDP, SOL_UDP for UDP protocol
$socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

if ($socket === false) {
    $errorCode = socket_last_error();
    $errorMessage = socket_strerror($errorCode);
    echo "Error creating socket: [{$errorCode}] {$errorMessage}\n";
    exit(1);
}

echo "Socket created successfully.\n";

if ($fadeDuration === 0.0) {
    // If fade duration is 0, immediately set the target level (toLevel)
    echo "Setting DMX Universe: {$dmxUniverse}, Channel: {$dmxChannel} to {$toLevel} immediately...\n";
    if (!sendOscDmxMessage($socket, $targetIp, $targetPort, $oscAddress, $oscTypeTag, $dmxChannel, $toLevel)) {
        echo "Failed to set DMX Universe {$dmxUniverse}, Channel {$dmxChannel} to {$toLevel}.\n";
    } else {
        echo "DMX Universe {$dmxUniverse}, Channel {$dmxChannel} set to {$toLevel} successfully.\n";
    }
} else {
    // Perform a fade
    $startLevel = $fromLevel; // Use the provided 'from_level'
    $endLevel = $toLevel;     // Use the provided 'to_level'
    $numSteps = 100; // Number of messages to send during the fade for smoothness
    $timePerStep = $fadeDuration / $numSteps; // Time in seconds per step
    $levelChangePerStep = ($endLevel - $startLevel) / ($numSteps > 1 ? $numSteps - 1 : 1); // Calculate level change per step

    echo "Fading DMX Universe: {$dmxUniverse}, Channel: {$dmxChannel} from {$startLevel} to {$endLevel} over {$fadeDuration} seconds...\n";

    for ($i = 0; $i < $numSteps; $i++) {
        $currentLevel = round($startLevel + ($levelChangePerStep * $i));

        // Clamp level to 0-255 just in case of floating point inaccuracies
        $currentLevel = max(0, min(255, $currentLevel));

        if (!sendOscDmxMessage($socket, $targetIp, $targetPort, $oscAddress, $oscTypeTag, $dmxChannel, $currentLevel)) {
            echo "Failed to send message for step {$i} at level {$currentLevel}.\n";
            // Optionally, break the loop or handle error differently
        } else {
            // echo "Sent DMX Universe {$dmxUniverse}, Channel {$dmxChannel} Level {$currentLevel}\n"; // Uncomment for verbose output
        }

        // Delay for the next step, unless it's the last step
        if ($i < $numSteps - 1) {
            usleep((int)($timePerStep * 1000000)); // usleep expects microseconds
        }
    }

    // Ensure the final level is sent accurately after the loop
    // This is important if rounding in the loop caused the last sent level to be slightly off.
    if ($numSteps > 0) { // Only send final if steps were taken (i.e., not a 0-sec fade)
        if (!sendOscDmxMessage($socket, $targetIp, $targetPort, $oscAddress, $oscTypeTag, $dmxChannel, $endLevel)) {
            echo "Failed to send final level message for Universe {$dmxUniverse}, Channel {$dmxChannel} to {$endLevel}.\n";
        } else {
            // echo "Sent final DMX Universe {$dmxUniverse}, Channel {$dmxChannel} Level {$endLevel}\n";
        }
    }

    echo "Fade complete for DMX Universe: {$dmxUniverse}, Channel: {$dmxChannel}. Final level: {$endLevel}.\n";
}


// 4. Close the socket
socket_close($socket);
echo "Socket closed.\n";

?>
