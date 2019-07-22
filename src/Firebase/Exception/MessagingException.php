<?php

declare(strict_types=1);

namespace Kreait\Firebase\Exception;

use GuzzleHttp\Exception\RequestException;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\Messaging\UnknownError;
use Kreait\Firebase\Util\JSON;
use Psr\Http\Message\ResponseInterface;

class MessagingException extends \RuntimeException implements FirebaseException
{
    /**
     * @var ResponseInterface|null
     */
    private $response;

    /**
     * @var array
     */
    private $errors = [];

    public static function fromRequestException(RequestException $e): self
    {
        $errors = [];
        $message = 'Unknown error';
        $code = $e->getCode();

        if ($response = $e->getResponse()) {
            $errors = self::getErrorsFromResponse($response);
            $message = $response->getReasonPhrase();
        }

        if (\is_array($errors['error'] ?? null)) {
            $code = (int) ($errors['error']['code'] ?? $code);
            $message = $errors['error']['message'] ?? $message;
        } elseif (\is_string($errors['error'] ?? null)) {
            $message = $errors['error'];
        }

        return self::createExceptionMessage($code, $message, $e)
            ->withResponse($response)
            ->withErrors($errors);
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $errors = self::getErrorsFromResponse($response);

        $code = (int) ($errors['error']['code'] ?? $response->getStatusCode());
        $message = $errors['error']['message'] ?? $response->getReasonPhrase();

        return self::createExceptionMessage($code, $message)
            ->withResponse($response)
            ->withErrors($errors);
    }

    private static function createExceptionMessage($code, $message, $e = null)
    {
        switch ($code) {
            case 400:
                return new InvalidMessage($message ?: 'Invalid message', $code, $e);
            case 401:
            case 403:
                return new AuthenticationError($message ?: 'Authentication error', $code, $e);
            case 404:
                return new NotFound($message ?: 'Not found', $code, $e);
            case 500:
                return new ServerError($message ?: 'Server error', $code, $e);
            case 503:
                return new ServerUnavailable($message ?: 'Server unavailable', $code, $e);
            default:
                return new UnknownError($message ?: 'Unknown error', $code, $e);
        }
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function withErrors(array $errors = [])
    {
        $e = new static($this->getMessage(), $this->getCode());
        $e->response = $this->response;
        $e->errors = $errors;

        return $e;
    }

    /**
     * @return ResponseInterface|null
     */
    public function response()
    {
        return $this->response;
    }

    public function withResponse(ResponseInterface $response = null)
    {
        $e = new static($this->getMessage(), $this->getCode());
        $e->errors = $this->errors;
        $e->response = $response;

        return $e;
    }

    private static function getErrorsFromResponse(ResponseInterface $response): array
    {
        try {
            return JSON::decode((string) $response->getBody(), true);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
    }
}
