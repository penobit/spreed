<?php

declare(strict_types=1);
/**
 *
 * @copyright Copyright (c) 2017, Daniel Calviño Sánchez (danxuliu@gmail.com)
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

namespace OCA\Talk\Controller;

use OCA\Talk\Chat\AutoComplete\SearchPlugin;
use OCA\Talk\Chat\AutoComplete\Sorter;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Chat\ReactionManager;
use OCA\Talk\Exceptions\UnauthorizedException;
use OCA\Talk\GuestManager;
use OCA\Talk\Manager;
use OCA\Talk\MatterbridgeManager;
use OCA\Talk\Middleware\Attribute\RequireModeratorOrNoLobby;
use OCA\Talk\Middleware\Attribute\RequireModeratorParticipant;
use OCA\Talk\Middleware\Attribute\RequireParticipant;
use OCA\Talk\Middleware\Attribute\RequirePermission;
use OCA\Talk\Middleware\Attribute\RequireReadWriteConversation;
use OCA\Talk\Middleware\Attribute\RequireRoom;
use OCA\Talk\Model\Attachment;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Message;
use OCA\Talk\Model\Session;
use OCA\Talk\Model\Webhook;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\AttachmentService;
use OCA\Talk\Service\AvatarService;
use OCA\Talk\Service\ChecksumVerificationService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\SessionService;
use OCA\Talk\Service\WebhookService;
use OCA\Talk\Share\RoomShareProvider;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Collaboration\AutoComplete\IManager;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Comments\IComment;
use OCP\Comments\MessageTooLongException;
use OCP\Comments\NotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\RichObjectStrings\InvalidObjectExeption;
use OCP\RichObjectStrings\IValidator;
use OCP\Security\ITrustedDomainHelper;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\User\Events\UserLiveStatusEvent;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;

class WebhookController extends AEnvironmentAwareController {
	private ?string $userId;
	private IUserManager $userManager;
	private IAppManager $appManager;
	private ChatManager $chatManager;
	private ReactionManager $reactionManager;
	private ParticipantService $participantService;
	private SessionService $sessionService;
	protected AttachmentService $attachmentService;
	protected avatarService $avatarService;
	private GuestManager $guestManager;
	/** @var string[] */
	protected array $guestNames;
	private MessageParser $messageParser;
	protected RoomShareProvider $shareProvider;
	private IManager $autoCompleteManager;
	private IUserStatusManager $statusManager;
	protected MatterbridgeManager $matterbridgeManager;
	private SearchPlugin $searchPlugin;
	private ISearchResult $searchResult;
	protected ITimeFactory $timeFactory;
	protected IEventDispatcher $eventDispatcher;
	protected IValidator $richObjectValidator;
	protected ITrustedDomainHelper $trustedDomainHelper;
	private IL10N $l;

	public function __construct(
		string $appName,
		IRequest $request,
		ChatManager $chatManager,
		ParticipantService $participantService,
		AttachmentService $attachmentService,
		avatarService $avatarService,
		MessageParser $messageParser,
		RoomShareProvider $shareProvider,
		MatterbridgeManager $matterbridgeManager,
		ITimeFactory $timeFactory,
		IEventDispatcher $eventDispatcher,
		IValidator $richObjectValidator,
		ITrustedDomainHelper $trustedDomainHelper,
		IL10N $l,
		protected ChecksumVerificationService $checksumVerificationService,
		protected WebhookService $webhookService,
		protected Manager $manager,
	) {
		parent::__construct($appName, $request);
		$this->chatManager = $chatManager;
		$this->participantService = $participantService;
		$this->attachmentService = $attachmentService;
		$this->avatarService = $avatarService;
		$this->messageParser = $messageParser;
		$this->shareProvider = $shareProvider;
		$this->matterbridgeManager = $matterbridgeManager;
		$this->timeFactory = $timeFactory;
		$this->eventDispatcher = $eventDispatcher;
		$this->richObjectValidator = $richObjectValidator;
		$this->trustedDomainHelper = $trustedDomainHelper;
		$this->l = $l;
	}


	public function parseCommentToResponse(IComment $comment, Message $parentMessage = null): DataResponse {
		$chatMessage = $this->messageParser->createMessage($this->room, $this->participant, $comment, $this->l);
		$this->messageParser->parseMessage($chatMessage);

		if (!$chatMessage->getVisibility()) {
			$response = new DataResponse([], Http::STATUS_CREATED);
			if ($this->participant->getAttendee()->getReadPrivacy() === Participant::PRIVACY_PUBLIC) {
				$response->addHeader('X-Chat-Last-Common-Read', (string) $this->chatManager->getLastCommonReadMessage($this->room));
			}
			return $response;
		}

		$this->participantService->updateLastReadMessage($this->participant, (int) $comment->getId());

		$data = $chatMessage->toArray($this->getResponseFormat());
		if ($parentMessage instanceof Message) {
			$data['parent'] = $parentMessage->toArray($this->getResponseFormat());
		}

		$response = new DataResponse($data, Http::STATUS_CREATED);
		if ($this->participant->getAttendee()->getReadPrivacy() === Participant::PRIVACY_PUBLIC) {
			$response->addHeader('X-Chat-Last-Common-Read', (string) $this->chatManager->getLastCommonReadMessage($this->room));
		}
		return $response;
	}

	/**
	 * Sends a new chat message to the given room.
	 *
	 * The author and timestamp are automatically set to the current user/guest
	 * and time.
	 *
	 * @param string $token conversation token
	 * @param string $message the message to send
	 * @param string $referenceId for the message to be able to later identify it again
	 * @param int $replyTo Parent id which this message is a reply to
	 * @param bool $silent If sent silent the chat message will not create any notifications
	 * @return DataResponse the status code is "201 Created" if successful, and
	 *         "404 Not found" if the room or session for a guest user was not
	 *         found".
	 */
	#[BruteForceProtection(action: 'webhook')]
	#[PublicPage]
	public function sendMessage(string $token, string $message, string $referenceId = '', int $replyTo = 0, bool $silent = false): DataResponse {


		\OC::$server->getLogger()->error('Entry');
		$random = $this->request->getHeader('Talk-Webhook-Random');
		if (empty($random) || strlen($random) < 32) {
			\OC::$server->getLogger()->error('Wrong Random');
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$checksum = $this->request->getHeader('Talk-Webhook-Checksum');
		if (empty($checksum)) {
			\OC::$server->getLogger()->error('Wrong Checksum');
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$webhooks = $this->webhookService->getWebhooksForToken($token);
		$webhook = null;
		foreach ($webhooks as $webhookAttempt) {
			try {
				$this->checksumVerificationService->validateRequest(
					$random,
					$checksum,
					$webhookAttempt->getSecret(),
					$message
				);
				$webhook = $webhookAttempt;
				break;
			} catch (UnauthorizedException) {
			}
		}

		if (!$webhook instanceof Webhook) {
			\OC::$server->getLogger()->error('No Webhook found');
			$response = new DataResponse([], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'webhook']);
			return $response;
		}

		$room = $this->manager->getRoomByToken($token);

		$actorType = 'bots';
		$actorId = 'webhook-' . $webhook->getUrlHash();

		$parent = $parentMessage = null;
//		if ($replyTo !== 0) {
//			try {
//				$parent = $this->chatManager->getParentComment($this->room, (string) $replyTo);
//			} catch (NotFoundException $e) {
//				// Someone is trying to reply cross-rooms or to a non-existing message
//				return new DataResponse([], Http::STATUS_BAD_REQUEST);
//			}
//
//			$parentMessage = $this->messageParser->createMessage($this->room, $this->participant, $parent, $this->l);
//			$this->messageParser->parseMessage($parentMessage);
//			if (!$parentMessage->isReplyable()) {
//				return new DataResponse([], Http::STATUS_BAD_REQUEST);
//			}
//		}

//		$this->participantService->ensureOneToOneRoomIsFilled($this->room);
		$creationDateTime = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'));

		try {
			$comment = $this->chatManager->sendMessage($room, $this->participant, $actorType, $actorId, $message, $creationDateTime, $parent, $referenceId, $silent);
		} catch (MessageTooLongException) {
			return new DataResponse([], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		} catch (\Exception) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse([], Http::STATUS_CREATED);
		return $this->parseCommentToResponse($comment, $parentMessage);
	}
}
