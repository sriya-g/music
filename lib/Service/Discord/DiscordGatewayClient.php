<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service\Discord;

use OCA\Music\AppFramework\Core\Logger;

class DiscordGatewayClient {

	/** @var resource|null */
	private $socket = null;
	private string $readBuffer = '';
	private ?int $lastSequence = null;
	private ?int $lastCloseCode = null;
	private string $lastCloseReason = '';

	public function __construct(
		private ?Logger $logger = null,
	) {
	}

	public function connect(string $gatewayUrl = 'wss://gateway.discord.gg/?v=9&encoding=json', int $timeout = 10): bool {
		$this->disconnect();

		$parsed = \parse_url($gatewayUrl);
		$host = $parsed['host'] ?? 'gateway.discord.gg';
		$port = $parsed['port'] ?? 443;
		$path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

		$context = \stream_context_create([
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			]
		]);

		$errno = 0;
		$errstr = '';
		$fp = @\stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
		if (!$fp) {
			$this->logger?->warning("Discord gateway connection failed: {$errstr} ({$errno})");
			return false;
		}

		\stream_set_timeout($fp, $timeout);

		$key = \base64_encode(\random_bytes(16));
		$headers = "GET {$path} HTTP/1.1\r\n" .
			"Host: {$host}\r\n" .
			"Upgrade: websocket\r\n" .
			"Connection: Upgrade\r\n" .
			"Sec-WebSocket-Key: {$key}\r\n" .
			"Sec-WebSocket-Version: 13\r\n" .
			"User-Agent: NextcloudMusic/1.0\r\n\r\n";

		\fwrite($fp, $headers);

		$response = '';
		while (!\feof($fp)) {
			$line = \fgets($fp, 1024);
			if ($line === false) {
				break;
			}
			$response .= $line;
			if (\rtrim($line) === '') {
				break;
			}
		}

		if (!\str_contains($response, '101 Switching Protocols')) {
			$this->logger?->warning("Discord gateway handshake failed: {$response}");
			\fclose($fp);
			return false;
		}

		// Switch to non-blocking mode for event loop
		\stream_set_blocking($fp, false);

		$this->socket = $fp;
		$this->readBuffer = '';
		$this->lastSequence = null;

		return true;
	}

	public function isConnected(): bool {
		return $this->socket !== null && \is_resource($this->socket) && !\feof($this->socket);
	}

	public function disconnect(): void {
		if ($this->socket !== null) {
			if (\is_resource($this->socket)) {
				// Send close frame (Opcode 8)
				@\fwrite($this->socket, $this->encodeFrame('', 8));
				@\fclose($this->socket);
			}
			$this->socket = null;
		}
		$this->readBuffer = '';
	}

	/**
	 * Send a JSON-serializable payload with a Discord Opcode
	 *
	 * @param int $op Discord Opcode (e.g. 1 for heartbeat, 2 for identify, 3 for presence)
	 * @param mixed $data Payload data
	 */
	public function sendOp(int $op, mixed $data): bool {
		$payload = \json_encode([
			'op' => $op,
			'd' => $data,
		]);
		return $this->send($payload);
	}

	public function send(string $payload): bool {
		if (!$this->isConnected()) {
			return false;
		}
		$frame = $this->encodeFrame($payload, 1);
		$len = \strlen($frame);
		$written = @\fwrite($this->socket, $frame);
		return $written === $len;
	}

	/**
	 * Read incoming messages from the socket.
	 *
	 * @return array<array<string, mixed>> List of decoded Discord payloads
	 */
	public function readMessages(): array {
		if (!$this->isConnected()) {
			return [];
		}

		$chunk = @\fread($this->socket, 8192);
		if ($chunk !== false && \strlen($chunk) > 0) {
			$this->readBuffer .= $chunk;
		}

		$messages = [];

		while (\strlen($this->readBuffer) >= 2) {
			$b0 = \ord($this->readBuffer[0]);
			$b1 = \ord($this->readBuffer[1]);

			$opcode = $b0 & 0x0F;
			$isMasked = ($b1 & 0x80) !== 0;
			$payloadLen = $b1 & 0x7F;
			$offset = 2;

			if ($payloadLen === 126) {
				if (\strlen($this->readBuffer) < 4) {
					break; // wait for more bytes
				}
				$payloadLen = \unpack('n', \substr($this->readBuffer, 2, 2))[1];
				$offset = 4;
			} elseif ($payloadLen === 127) {
				if (\strlen($this->readBuffer) < 10) {
					break; // wait for more bytes
				}
				$payloadLen = \unpack('J', \substr($this->readBuffer, 2, 8))[1];
				$offset = 10;
			}

			$maskKey = '';
			if ($isMasked) {
				if (\strlen($this->readBuffer) < $offset + 4) {
					break;
				}
				$maskKey = \substr($this->readBuffer, $offset, 4);
				$offset += 4;
			}

			if (\strlen($this->readBuffer) < $offset + $payloadLen) {
				break; // entire frame payload not received yet
			}

			$rawPayload = \substr($this->readBuffer, $offset, $payloadLen);
			$this->readBuffer = \substr($this->readBuffer, $offset + $payloadLen);

			if ($isMasked) {
				$unmasked = '';
				for ($i = 0; $i < $payloadLen; $i++) {
					$unmasked .= $rawPayload[$i] ^ $maskKey[$i % 4];
				}
				$rawPayload = $unmasked;
			}

			// Handle opcodes
			if ($opcode === 9) {
				// Ping -> send Pong
				@\fwrite($this->socket, $this->encodeFrame($rawPayload, 10));
			} elseif ($opcode === 8) {
				// Close frame
				$code = null;
				$reason = '';
				if (\strlen($rawPayload) >= 2) {
					$code = \unpack('n', \substr($rawPayload, 0, 2))[1];
					$reason = \substr($rawPayload, 2);
				}
				$this->lastCloseCode = $code;
				$this->lastCloseReason = $reason;
				$this->logger?->warning("Discord gateway closed connection. Code: {$code}, Reason: {$reason}");
				$this->disconnect();
				break;
			} elseif ($opcode === 1) {
				// Text frame
				$decoded = \json_decode($rawPayload, true);
				if (\is_array($decoded)) {
					if (isset($decoded['s']) && $decoded['s'] !== null) {
						$this->lastSequence = (int)$decoded['s'];
					}
					$messages[] = $decoded;
				}
			}
		}

		return $messages;
	}

	public function getLastCloseCode(): ?int {
		return $this->lastCloseCode;
	}

	public function getLastCloseReason(): string {
		return $this->lastCloseReason;
	}

	public function getLastSequence(): ?int {
		return $this->lastSequence;
	}

	/**
	 * @return resource|null
	 */
	public function getSocket() {
		return $this->socket;
	}

	private function encodeFrame(string $payload, int $opcode): string {
		$b1 = 0x80 | ($opcode & 0x0F); // FIN = 1
		$length = \strlen($payload);

		if ($length <= 125) {
			$header = \pack('CC', $b1, 0x80 | $length);
		} elseif ($length <= 65535) {
			$header = \pack('CCn', $b1, 0x80 | 126, $length);
		} else {
			$header = \pack('CCNN', $b1, 0x80 | 127, ($length >> 32) & 0xFFFFFFFF, $length & 0xFFFFFFFF);
		}

		$mask = \random_bytes(4);
		$masked = '';
		for ($i = 0; $i < $length; $i++) {
			$masked .= $payload[$i] ^ $mask[$i % 4];
		}

		return $header . $mask . $masked;
	}
}
