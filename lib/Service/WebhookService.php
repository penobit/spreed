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

namespace OCA\Talk\Service;

use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Events\ChatParticipantEvent;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Webhook;
use OCA\Talk\Model\WebhookMapper;
use OCA\Talk\Room;
use OCA\Talk\TalkSession;
use OCP\Http\Client\IClientService;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;

class WebhookService {
	public function __construct(
		protected WebhookMapper $webhookMapper,
		protected IClientService $clientService,
		protected IUserSession $userSession,
		protected TalkSession $talkSession,
		protected ISession $session,
		protected ISecureRandom $secureRandom,
		protected IURLGenerator $urlGenerator,
		protected MessageParser $messageParser,
		protected IFactory $l10nFactory,
	) {
	}

	public function afterChatMessageSent(ChatParticipantEvent $event): void {
		$webhooks = $this->webhookMapper->findForToken($event->getRoom()->getToken());
		if (empty($webhooks)) {
			return;
		}

		$message = $this->messageParser->createMessage(
			$event->getRoom(),
			$event->getParticipant(),
			$event->getComment(),
			$this->l10nFactory->get('spreed', 'en', 'en')
		);
		$this->messageParser->parseMessage($message);
		$messageData = [
			'message' => $message->getMessage(),
			'parameters' => $message->getMessageParameters(),
		];

		$attendee = $event->getParticipant()->getAttendee();

		$this->sendAsyncRequests($webhooks, [
			'type' => 'Create',
			'actor' => [
				'type' => 'Person',
				'id' => $attendee->getActorType() . '/' . $attendee->getActorId(),
				'name' => $attendee->getDisplayName(),
			],
			'object' => [
				'type' => 'Note',
				'id' => $event->getComment()->getId(),
				'name' => json_encode($messageData),
			],
			'target' => [
				'type' => 'Collection',
				'id' => $event->getRoom()->getToken(),
				'name' => $event->getRoom()->getName(),
			]
		]);
	}

	/**
	 * @param Webhook[] $webhooks
	 * @param array $body
	 */
	protected function sendAsyncRequests(array $webhooks, array $body): void {
		$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

		foreach ($webhooks as $webhook) {
			$random = $this->secureRandom->generate(64);
			$hash = hash_hmac('sha256', $random . $jsonBody, $webhook->getSecret());
			$headers = [
				'Content-Type' => 'application/json',
				'X-Nextcloud-Talk-Random' => $random,
				'X-Nextcloud-Talk-Signature' => $hash,
				'X-Nextcloud-Talk-Backend' => $this->urlGenerator->getAbsoluteURL(''),
			];

			$data = [
				'verify' => false,
				'nextcloud' => [
					'allow_local_address' => true, // FIXME don't enforce
				],
				'headers' => $headers,
				'body' => json_encode($body),
			];

			$client = $this->clientService->newClient();
			$client->postAsync($webhook->getUrl(), $data);
		}
	}

	/**
	 * @param Room $room
	 * @return array
	 * @psalm-return array{type: string, id: string, name: string}
	 */
	protected function getActor(Room $room): array {
		if (\OC::$CLI || $this->session->exists('talk-overwrite-actor-cli')) {
			return [
				'type' => Attendee::ACTOR_GUESTS,
				'id' => 'cli',
				'name' => 'Administration',
			];
		}

		if ($this->session->exists('talk-overwrite-actor-type')) {
			return [
				'type' => $this->session->get('talk-overwrite-actor-type'),
				'id' => $this->session->get('talk-overwrite-actor-id'),
				'name' => $this->session->get('talk-overwrite-actor-displayname'),
			];
		}

		if ($this->session->exists('talk-overwrite-actor-id')) {
			return [
				'type' => Attendee::ACTOR_USERS,
				'id' => $this->session->get('talk-overwrite-actor-id'),
				'name' => $this->session->get('talk-overwrite-actor-displayname'),
			];
		}

		$user = $this->userSession->getUser();
		if ($user instanceof IUser) {
			return [
				'type' => Attendee::ACTOR_USERS,
				'id' => $user->getUID(),
				'name' => $user->getDisplayName(),
			];
		}

		$sessionId = $this->talkSession->getSessionForRoom($room->getToken());
		$actorId = $sessionId ? sha1($sessionId) : 'failed-to-get-session';
		return [
			'type' => Attendee::ACTOR_GUESTS,
			'id' => $actorId,
			'name' => $user->getDisplayName(),
		];
	}
}
