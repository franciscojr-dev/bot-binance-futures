<?php

namespace IBotMex\Core;

use \SQLite3;

class DB extends SQLite3
{
    public function __construct(string $db)
    {
        $this->open($db);
    }
}
