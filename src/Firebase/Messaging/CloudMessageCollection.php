<?php

declare(strict_types=1);

namespace Kreait\Firebase\Messaging;

use Traversable;

/**
 * Class CloudMessageCollection.
 */
class CloudMessageCollection implements \IteratorAggregate
{
    /**
     * @var array
     */
    private $messages;

    /**
     * CloudMessageCollection constructor.
     */
    public function __construct()
    {
        $this->messages = [];
    }

    /**
     * Retrieve an external iterator.
     *
     * @see https://php.net/manual/en/iteratoraggregate.getiterator.php
     *
     * @return Traversable An instance of an object implementing <b>Iterator</b> or
     * <b>Traversable</b>
     *
     * @since 5.0.0
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->messages);
    }

    /**
     * Append message.
     */
    public function addMessage(CloudMessage $message)
    {
        $this->messages[] = $message;
    }
}
