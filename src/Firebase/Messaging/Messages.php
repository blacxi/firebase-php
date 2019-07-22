<?php

declare(strict_types=1);

namespace Kreait\Firebase\Messaging;

use ArrayIterator;
use IteratorAggregate;

final class Messages implements IteratorAggregate
{
    /** @var Message[] */
    private $messages;

    /**
     * @param Message[] $messages
     */
    public function __construct(array $messages = [])
    {
        foreach ($messages as $message) {
            $this->add($message);
        }
    }

    public function getIterator()
    {
        return new ArrayIterator($this->messages);
    }

    public function add(Message $message)
    {
        $this->messages[] = $message;
    }
}
