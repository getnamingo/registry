<?php

class UserTest extends \PHPUnit\Framework\TestCase
{
    protected $user;

    public function setUp()
    {
        $this->user = new \App\Models\User;
    }

    public function testEmailVariablesContainCorrectValues()
    {
        $user = new \App\Models\User;

    }
}
