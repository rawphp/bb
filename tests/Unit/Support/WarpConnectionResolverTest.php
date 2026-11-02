<?php

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

it('points Eloquent back at the app\'s database manager after an isolated test left another one', function () {
    $original = Model::getConnectionResolver();
    Model::setConnectionResolver(Mockery::mock(ConnectionResolverInterface::class));

    try {
        TestCase::restoreConnectionResolver($this->app);

        expect(Model::getConnectionResolver())->toBe($this->app->make('db'));
    } finally {
        Model::setConnectionResolver($original);
    }
});
