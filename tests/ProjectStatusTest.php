<?php

declare(strict_types=1);

use App\Models\Project;

test('isArchived riconosce solo lo stato archived', function () {
    assertSame(true, Project::isArchived(['status' => 'archived']));
    assertSame(false, Project::isArchived(['status' => 'active']));
    assertSame(false, Project::isArchived([]));
});
