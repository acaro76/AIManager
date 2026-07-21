<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\Database;

abstract class BaseModel
{
    protected Database $db;

    public function __construct()
    {
        $this->db = App::get()->db;
    }
}
