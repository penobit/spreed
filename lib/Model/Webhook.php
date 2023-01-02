<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023, Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\Entity;

/**
 * @method void seName(string $name)
 * @method string getName()
 * @method void setUrl(string $url)
 * @method string getUrl()
 * @method void setDescription(string $description)
 * @method string getDescription()
 * @method void setSecret(string $secret)
 * @method string getSecret()
 * @method void setToken(string $token)
 * @method string getToken()
 */
class Webhook extends Entity implements \JsonSerializable {
	protected string $name = '';
	protected string $url = '';
	protected string $description = '';
	protected string $secret = '';
	protected string $token = '';

	public function __construct() {
		$this->addType('name', 'string');
		$this->addType('url', 'string');
		$this->addType('description', 'string');
		$this->addType('secret', 'string');
		$this->addType('token', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'url' => $this->getUrl(),
			'description' => $this->getDescription(),
			'secret' => $this->getSecret(),
			'token' => $this->getToken(),
		];
	}
}
