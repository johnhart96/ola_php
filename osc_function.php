<?php

/**
 * PHP Helper functions for OSC message construction.
 * These are required by the outputSACN function.
 */

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
 * This is an internal helper for outputSACN.
 *
 * @param resource $socket The UDP socket resource.
 * @param string $ip The target IP address.
 * @param int $port The target port.
 * @param string $address The OSC address pattern (e.g., /dmx/universe/1).
 * @param string $typeTag The OSC type tag (e.g., ,ii for two integers).
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

    // Attempt to send the data. Using @ to suppress warnings, and checking return.
    $bytesSent = @socket_sendto($socket, $payload, strlen($payload), 0, $ip, $port);

    if ($bytesSent === false) {
        $errorCode = socket_last_error($socket);
        $errorMessage = socket_strerror($errorCode);
        error_log("OSC Send Error (Universe: {$address}, Channel: {$channel}, Level: {$level}): [{$errorCode}] {$errorMessage}");
        return false;
    }
    return true;
}

/**
 * Sends an OSC DMX level message to an Open Lighting Architecture (OLA) receiver
 * with optional linear fading between two levels.
 *
 * @param int $universe The DMX Universe number (e.g., 0, 1, 2...).
 * @param int $channel The DMX Channel number (1-512).
 * @param int $fromLevel The starting DMX level (0-255).
 * @param int $toLevel The target DMX level (0-255).
 * @param float $fadeDuration The duration of the fade in seconds (0 for immediate).
 * @return bool True if the message(s) were sent successfully, false otherwise.
 */
function outputSACN(int $universe, int $channel, int $fromLevel, int $toLevel, float $fadeDuration): bool {
    // --- Configuration (can be made parameters if more flexibility is needed) ---
    $targetIp = '192.168.251.128'; // The IP address of the OSC receiver (OLA)
    $targetPort = 7770;            // The port number for the OSC receiver
    $oscTypeTag = ',ii';            // Type tag: ',ii' for two integers (channel number, level)

    // --- Input Validation ---
    if ($universe < 0) {
        error_log("Error: DMX Universe cannot be negative. Provided: {$universe}");
        return false;
    }
    if ($channel < 1 || $channel > 512) {
        error_log("Error: DMX Channel must be between 1 and 512. Provided: {$channel}");
        return false;
    }
    if ($fromLevel < 0 || $fromLevel > 255) {
        error_log("Error: 'From' DMX Level must be between 0 and 255. Provided: {$fromLevel}");
        return false;
    }
    if ($toLevel < 0 || $toLevel > 255) {
        error_log("Error: 'To' DMX Level must be between 0 and 255. Provided: {$toLevel}");
        return false;
    }
    if ($fadeDuration < 0) {
        error_log("Error: Fade duration cannot be negative. Provided: {$fadeDuration}");
        return false;
    }

    // OSC address for the specified Universe
    $oscAddress = '/dmx/universe/' . $universe;

    // --- Create a UDP socket ---
    $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($socket === false) {
        $errorCode = socket_last_error();
        $errorMessage = socket_strerror($errorCode);
        error_log("Error creating socket: [{$errorCode}] {$errorMessage}");
        return false;
    }

    $success = true; // Assume success initially

    if ($fadeDuration === 0.0) {
        // If fade duration is 0, immediately set the target level (toLevel)
        echo "Setting DMX Universe: {$universe}, Channel: {$channel} to {$toLevel} immediately...\n";
        if (!sendOscDmxMessage($socket, $targetIp, $targetPort, $oscAddress, $oscTypeTag, $channel, $toLevel)) {
            $success = false;
            echo "Failed to set DMX Universe {$universe}, Channel {$channel} to {$toLevel}.\n";
        } else {
            echo "DMX Universe {$universe}, Channel {$channel} set to {$toLevel} successfully.\n";
        }
    } else {
        // Perform a fade
        $numSteps = 100; // Number of messages to send during the fade for smoothness
        $timePerStep = $fadeDuration / $numSteps; // Time in seconds per step
        // Calculate level change per step. Use $numSteps-1 for accurate last step value if numSteps > 1
        $levelChangePerStep = ($toLevel - $fromLevel) / ($numSteps > 1 ? $numSteps - 1 : 1);

        echo "Fading DMX Universe: {$universe}, Channel: {$channel} from {$fromLevel} to {$toLevel} over {$fadeDuration} seconds...\n";

        for ($i = 0; $i < $numSteps; $i++) {
            $currentLevel = round($fromLevel + ($levelChangePerStep * $i));

            // Clamp level to 0-255 just in case of floating point inaccuracies
            $currentLevel = max(0, min(255, $currentLevel));

            if (!sendOscDmxMessage($socket, $targetIp, $targetPort, $oscAddress, $oscTypeTag, $channel, $currentLevel)) {
                $success = false;
                echo "Failed to send message for step {$i} at level {$currentLevel}.\n";
                // Optionally, break the loop or handle error differently
            }

            // Delay for the next step, unless it's the last step
            if ($i < $numSteps - 1) {
                usleep((int)($timePerStep * 1000000)); // usleep expects microseconds
            }
        }

        // Ensure the final level is sent accurately after the loop
        // This is important if rounding in the loop caused the last sent level to be slightly off.
        if ($numSteps > 0) {
            if (!sendOscDmxMessage($socket, $targetIp, $targetPort, $oscAddress, $oscTypeTag, $channel, $toLevel)) {
                $success = false;
                echo "Failed to send final level message for Universe {$universe}, Channel {$channel} to {$toLevel}.\n";
            }
        }

        echo "Fade complete for DMX Universe: {$universe}, Channel: {$channel}. Final level: {$toLevel}.\n";
    }

    // --- Close the socket ---
    socket_close($socket);
    echo "Socket closed.\n";

    return $success;
}

?>
