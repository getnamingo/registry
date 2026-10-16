<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

namespace App\Lib;

use Respect\Validation\Validator as Respect;
use Respect\Validation\Exceptions\NestedValidationException;

class Validator
{
	protected $errors;

	public function validate($request, array $rules)
	{
		$data = $request->getParsedBody();

		foreach ($rules as $field => $rule) {
		    $fieldName = str_replace('_',' ',$field);
			try {
				$rule->setName(ucfirst($fieldName))->assert($data[$field]);
			} catch (NestedValidationException $e) {
				$this->errors[$field] = $e->getMessages();
			}
		}
		$_SESSION['errors'] = $this->errors;
		return $this;
	}

	public function failed()
	{
		return !empty($this->errors);
	}
}