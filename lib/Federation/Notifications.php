<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Gary Kim <gary@garykim.dev>
 *
 * @author Gary Kim <gary@garykim.dev>
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

namespace OCA\Talk\Federation;

use OCA\FederatedFileSharing\AddressHandler;
use OCA\Talk\AppInfo\Application;
use OCA\Talk\BackgroundJob\RetryJob;
use OCA\Talk\Exceptions\RoomHasNoModeratorException;
use OCA\Talk\MatterbridgeManager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\BackgroundJob\IJobList;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationNotification;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class Notifications {
	/** @var ICloudFederationFactory */
	private $cloudFederationFactory;

	/** @var AddressHandler */
	private $addressHandler;

	/** @var LoggerInterface */
	private $logger;

	/** @var ICloudFederationProviderManager */
	private $federationProviderManager;

	/** @var IJobList */
	private $jobList;

	/** @var IUserManager */
	private $userManager;

	/** @var AttendeeMapper */
	private $attendeeMapper;

	/** @var MatterbridgeManager */
	private $matterbridgeManager;

	public function __construct(
		ICloudFederationFactory $cloudFederationFactory,
		AddressHandler $addressHandler,
		LoggerInterface $logger,
		ICloudFederationProviderManager $federationProviderManager,
		IJobList $jobList,
		IUserManager $userManager,
		AttendeeMapper $attendeeMapper,
		MatterbridgeManager $matterbridgeManager
	) {
		$this->cloudFederationFactory = $cloudFederationFactory;
		$this->addressHandler = $addressHandler;
		$this->logger = $logger;
		$this->federationProviderManager = $federationProviderManager;
		$this->jobList = $jobList;
		$this->userManager = $userManager;
		$this->attendeeMapper = $attendeeMapper;
		$this->matterbridgeManager = $matterbridgeManager;
	}

	/**
	 * @throws \OCP\HintException
	 * @throws RoomHasNoModeratorException
	 * @throws \OCP\DB\Exception
	 */
	public function sendRemoteShare(string $providerId, string $token, string $shareWith, string $sharedBy,
									string $sharedByFederatedId, string $shareType, Room $room): bool {
		[$user, $remote] = $this->addressHandler->splitUserRemote($shareWith);

		$roomName = $room->getName();
		$roomType = $room->getType();
		$roomToken = $room->getToken();

		if (!($user && $remote)) {
			$this->logger->info(
				"could not share $roomToken, invalid contact $shareWith",
				['app' => Application::APP_ID]
			);
			return false;
		}

		/** @var IUser|null $roomOwner */
		$roomOwner = null;
		try {
			$roomOwners = $this->attendeeMapper->getActorsByParticipantTypes($room->getId(), [Participant::OWNER]);
			if (!empty($roomOwners) && $roomOwners[0]->getActorType() === Attendee::ACTOR_USERS) {
				$roomOwner = $this->userManager->get($roomOwners[0]->getActorId());
			}
		} catch (\Exception $e) {
			// Get a local moderator instead
			try {
				$roomOwners = $this->attendeeMapper->getActorsByParticipantTypes($room->getId(), [Participant::MODERATOR]);
				if (!empty($roomOwners) && $roomOwners[0]->getActorType() === Attendee::ACTOR_USERS) {
					$roomOwner = $this->userManager->get($roomOwners[0]->getActorId());
				}
			} catch (\Exception $e) {
				throw new RoomHasNoModeratorException();
			}
		}

		$remote = $this->prepareRemoteUrl($remote);

		$share = $this->cloudFederationFactory->getCloudFederationShare(
			$user . '@' . $remote,
			$roomToken,
			'',
			$providerId,
			$roomOwner->getCloudId(),
			$roomOwner->getDisplayName(),
			$sharedByFederatedId,
			$sharedBy,
			$token,
			$shareType,
			FederationManager::TALK_ROOM_RESOURCE
		);

		// Put room name info in the share
		$protocol = $share->getProtocol();
		$protocol['roomName'] = $roomName;
		$protocol['roomType'] = $roomType;
		$protocol['name'] = 'nctalk';
		$share->setProtocol($protocol);

		$response = $this->federationProviderManager->sendShare($share);
		if (is_array($response)) {
			return true;
		}
		$this->logger->info(
			"failed sharing $roomToken with $shareWith",
			['app' => Application::APP_ID]
		);

		return false;
	}

	/**
	 * send remote share acceptance notification to remote server
	 *
	 * @param string $remote remote server domain
	 * @param string $id share id
	 * @param string $token share secret token
	 * @return bool success
	 */
	public function sendShareAccepted(string $remote, string $id, string $token): bool {
		$remote = $this->prepareRemoteUrl($remote);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			'SHARE_ACCEPTED',
		FederationManager::TALK_ROOM_RESOURCE,
		$id,
		[
			'sharedSecret' => $token,
			'message' => 'Recipient accepted the share',
		]);
		$response = $this->federationProviderManager->sendNotification($remote, $notification);
		if (!is_array($response)) {
			$this->logger->info(
				"failed to send share accepted notification for share from $remote",
				['app' => Application::APP_ID]
			);
			return false;
		}
		return true;
	}

	public function sendShareDeclined(string $remote, string $id, string $token): bool {
		$remote = $this->prepareRemoteUrl($remote);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			'SHARE_DECLINED',
			FederationManager::TALK_ROOM_RESOURCE,
			$id,
			[
				'sharedSecret' => $token,
				'message' => 'Recipient declined the share',
			]
		);
		$response = $this->federationProviderManager->sendNotification($remote, $notification);
		if (!is_array($response)) {
			$this->logger->info(
				"failed to send share declined notification for share from $remote",
				['app' => Application::APP_ID]
			);
			return false;
		}
		return true;
	}

	public function sendRemoteUnShare(string $remote, string $id, string $token) {
		$remote = $this->prepareRemoteUrl($remote);

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			'SHARE_UNSHARED',
			FederationManager::TALK_ROOM_RESOURCE,
			$id,
			[
				'sharedSecret' => $token,
				'message' => 'This room has been unshared',
			]
		);

		$this->sendUpdateToRemote($remote, $notification);
	}

	public function sendUpdateDataToRemote(string $remote, array $data = [], int $try = 0) {
		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			$data['notificationType'],
			$data['resourceType'],
			$data['providerId'],
			$data['notification']
		);
		$this->sendUpdateToRemote($remote, $notification, $try);
	}

	public function sendUpdateToRemote(string $remote, ICloudFederationNotification $notification, int $try = 0) {
		$response = $this->federationProviderManager->sendNotification($remote, $notification);
		if (!is_array($response)) {
			$this->jobList->add(RetryJob::class,
				[
					'remote' => $remote,
					'data' => json_encode($notification->getMessage()),
					'try' => $try,
				]
			);
		}
	}

	private function prepareRemoteUrl(string $remote): string {
		if ($this->addressHandler->urlContainProtocol($remote)) {
			return "https://" . $remote;
		}
		return $remote;
	}
}
