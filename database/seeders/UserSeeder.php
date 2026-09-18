<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
	use WithoutModelEvents;

	public function run(): void
	{
		$user = User::updateOrCreate(
			['email' => 'customer@dev.com'],
			[
				'name' => 'Customer',
				'password' => 'asdfasdf',
				'email_verified_at' => now(),
			],
		);

		$user->syncRoles(['Owner']);
	}
}
