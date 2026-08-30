<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Forms\Tests\Unit\Service;

use OCA\Forms\BackgroundJob\SendConfirmationMailJob;
use OCA\Forms\Constants;
use OCA\Forms\Db\Answer;
use OCA\Forms\Db\AnswerMapper;
use OCA\Forms\Db\Form;
use OCA\Forms\Db\Option;
use OCA\Forms\Db\OptionMapper;
use OCA\Forms\Db\Question;
use OCA\Forms\Db\QuestionMapper;
use OCA\Forms\Db\Submission;
use OCA\Forms\Service\ConfigService;
use OCA\Forms\Service\ConfirmationEmailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IMemcache;
use OCP\Mail\IEmailValidator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ConfirmationEmailServiceTest extends TestCase {
	/** @var ConfirmationEmailService */
	private $service;

	/** @var ConfigService|MockObject */
	private $configService;

	/** @var AnswerMapper|MockObject */
	private $answerMapper;

	/** @var QuestionMapper|MockObject */
	private $questionMapper;

	/** @var OptionMapper|MockObject */
	private $optionMapper;

	/** @var IEmailValidator|MockObject */
	private $emailValidator;

	/** @var IJobList|MockObject */
	private $jobList;

	/** @var ICacheFactory|MockObject */
	private $cacheFactory;

	/** @var IMemcache|MockObject */
	private $cache;

	/** @var IL10N|MockObject */
	private $l10n;

	/** @var LoggerInterface|MockObject */
	private $logger;

	public function setUp(): void {
		parent::setUp();

		$this->configService = $this->createMock(ConfigService::class);
		$this->answerMapper = $this->createMock(AnswerMapper::class);
		$this->questionMapper = $this->createMock(QuestionMapper::class);
		$this->optionMapper = $this->createMock(OptionMapper::class);
		$this->emailValidator = $this->createMock(IEmailValidator::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(IMemcache::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->l10n->method('t')->willReturnCallback(
			fn (string $text, array $params = []) => $params ? vsprintf($text, $params) : $text
		);

		$this->cacheFactory->method('createDistributed')
			->with('forms_confirmation_email')
			->willReturn($this->cache);

		$this->service = new ConfirmationEmailService(
			$this->configService,
			$this->answerMapper,
			$this->questionMapper,
			$this->optionMapper,
			$this->emailValidator,
			$this->jobList,
			$this->cacheFactory,
			$this->l10n,
			$this->logger,
		);
	}

	private function createQuestionEntity(array $data): MockObject {
		$entity = $this->createMock(Question::class);
		$entity->method('read')->willReturn($data);
		return $entity;
	}

	private function makeAnswer(int $questionId, string $text, int $submissionId = 10): Answer {
		$answer = new Answer();
		$answer->setSubmissionId($submissionId);
		$answer->setQuestionId($questionId);
		$answer->setText($text);
		return $answer;
	}

	private function createOption(int $id, string $text, string $optionType = ''): Option {
		$option = new Option();
		$option->setId($id);
		$option->setText($text);
		$option->setOptionType($optionType);
		return $option;
	}

	private function emailQuestion(): MockObject {
		return $this->createQuestionEntity([
			'id' => 5,
			'type' => Constants::ANSWER_TYPE_SHORT,
			'text' => 'Email',
			'name' => 'email',
			'extraSettings' => ['validationType' => 'email'],
		]);
	}

	/**
	 * Configure the mocks for a form that does send a confirmation email and
	 * return the payload of the queued job.
	 *
	 * @param list<MockObject> $questions
	 * @param list<Answer> $answers
	 * @param array<int, list<Option>> $optionsByQuestion
	 * @return array{recipient: string, subject: string, body: string, formId: int, submissionId: int}
	 */
	private function queueAndCapture(
		array $questions,
		array $answers,
		string $body = 'Thanks',
		string $subject = 'Received',
		array $optionsByQuestion = [],
	): array {
		$form = Form::fromParams([
			'id' => 1,
			'title' => 'Survey',
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => 5,
			'confirmationEmailSubject' => $subject,
			'confirmationEmailBody' => $body,
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->configService->method('getConfirmationEmailRateLimit')->willReturn(3);
		$this->questionMapper->method('findByForm')->willReturn($questions);
		$this->answerMapper->method('findBySubmission')->willReturn($answers);
		$this->optionMapper->method('findByQuestion')
			->willReturnCallback(fn ($questionId, $optionType = null): array => $optionsByQuestion[$questionId] ?? []);
		$this->emailValidator->method('isValid')->willReturn(true);
		$this->cache->method('add')->willReturn(true);

		$captured = null;
		$this->jobList->expects($this->once())
			->method('add')
			->willReturnCallback(function ($job, $payload) use (&$captured): void {
				$captured = $payload;
			});

		$this->service->send($form, $submission);

		$this->assertIsArray($captured, 'No confirmation email was queued');
		return $captured;
	}

	public function testSendDoesNothingIfConfirmationEmailDisabled(): void {
		$form = Form::fromParams(['id' => 1, 'confirmationEmailEnabled' => false]);
		$submission = new Submission();
		$submission->setId(10);

		$this->jobList->expects($this->never())->method('add');

		$this->service->send($form, $submission);
	}

	public function testSendDoesNothingIfAdminDisabled(): void {
		$form = Form::fromParams(['id' => 1, 'confirmationEmailEnabled' => true]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(false);
		$this->jobList->expects($this->never())->method('add');

		$this->service->send($form, $submission);
	}

	public function testSendDoesNothingIfNoRecipientQuestion(): void {
		$form = Form::fromParams([
			'id' => 1,
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => null,
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->questionMapper->method('findByForm')->willReturn([]);
		$this->jobList->expects($this->never())->method('add');

		$this->service->send($form, $submission);
	}

	public function testSendDoesNothingIfRecipientQuestionChangedType(): void {
		$form = Form::fromParams([
			'id' => 1,
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => 5,
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->questionMapper->method('findByForm')->willReturn([
			$this->createQuestionEntity(['id' => 5, 'type' => Constants::ANSWER_TYPE_LONG]),
		]);
		$this->jobList->expects($this->never())->method('add');

		$this->service->send($form, $submission);
	}

	public function testSendDoesNothingIfNoEmailAnswer(): void {
		$form = Form::fromParams([
			'id' => 1,
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => 5,
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->questionMapper->method('findByForm')->willReturn([
			$this->createQuestionEntity([
				'id' => 5,
				'type' => Constants::ANSWER_TYPE_SHORT,
				'extraSettings' => ['validationType' => 'email'],
			]),
		]);
		$this->answerMapper->method('findBySubmission')->willReturn([]);
		$this->jobList->expects($this->never())->method('add');

		$this->service->send($form, $submission);
	}

	public function testSendDoesNothingIfEmailAnswerIsInvalid(): void {
		$form = Form::fromParams([
			'id' => 1,
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => 5,
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->questionMapper->method('findByForm')->willReturn([
			$this->createQuestionEntity([
				'id' => 5,
				'type' => Constants::ANSWER_TYPE_SHORT,
				'extraSettings' => ['validationType' => 'email'],
			]),
		]);
		$this->answerMapper->method('findBySubmission')->willReturn([
			$this->makeAnswer(5, 'not-an-email'),
		]);
		$this->emailValidator->method('isValid')->with('not-an-email')->willReturn(false);
		$this->jobList->expects($this->never())->method('add');

		$this->service->send($form, $submission);
	}

	public function testSendQueuesJobWithDefaultSubjectAndBody(): void {
		$form = Form::fromParams([
			'id' => 1,
			'title' => 'My Form',
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => 5,
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->configService->method('getConfirmationEmailRateLimit')->willReturn(3);
		$this->questionMapper->method('findByForm')->willReturn([
			$this->createQuestionEntity([
				'id' => 5,
				'type' => Constants::ANSWER_TYPE_SHORT,
				'extraSettings' => ['validationType' => 'email'],
			]),
		]);
		$this->answerMapper->method('findBySubmission')->willReturn([
			$this->makeAnswer(5, 'user@example.com'),
		]);
		$this->emailValidator->method('isValid')->with('user@example.com')->willReturn(true);

		$this->cache->method('add')->willReturn(true);

		$this->jobList->expects($this->once())
			->method('add')
			->with(
				SendConfirmationMailJob::class,
				$this->callback(function (array $payload): bool {
					$this->assertSame('user@example.com', $payload['recipient']);
					$this->assertSame('Thank you for your submission', $payload['subject']);
					$this->assertStringContainsString('My Form', $payload['body']);
					$this->assertSame(1, $payload['formId']);
					$this->assertSame(10, $payload['submissionId']);
					return true;
				})
			);

		$this->service->send($form, $submission);
	}

	public function testSendQueuesJobWithCustomSubjectAndBodyAndPlaceholders(): void {
		$form = Form::fromParams([
			'id' => 1,
			'title' => 'Survey',
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => 5,
			'confirmationEmailSubject' => 'Hi {name}',
			'confirmationEmailBody' => 'Your answer: {email}',
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->configService->method('getConfirmationEmailRateLimit')->willReturn(3);
		$this->questionMapper->method('findByForm')->willReturn([
			$this->createQuestionEntity([
				'id' => 3,
				'type' => Constants::ANSWER_TYPE_SHORT,
				'text' => 'Name',
				'name' => 'name',
			]),
			$this->createQuestionEntity([
				'id' => 5,
				'type' => Constants::ANSWER_TYPE_SHORT,
				'text' => 'Email',
				'name' => 'email',
				'extraSettings' => ['validationType' => 'email'],
			]),
		]);

		$nameAnswer = new Answer();
		$nameAnswer->setSubmissionId(10);
		$nameAnswer->setQuestionId(3);
		$nameAnswer->setText('Alice');

		$emailAnswer = $this->makeAnswer(5, 'alice@example.com');

		$this->answerMapper->method('findBySubmission')->willReturn([$nameAnswer, $emailAnswer]);
		$this->emailValidator->method('isValid')->willReturn(true);

		$this->cache->method('add')->willReturn(true);

		$this->jobList->expects($this->once())
			->method('add')
			->with(
				SendConfirmationMailJob::class,
				$this->callback(function (array $payload): bool {
					$this->assertSame('Hi Alice', $payload['subject']);
					$this->assertSame('Your answer: alice@example.com', $payload['body']);
					return true;
				})
			);

		$this->service->send($form, $submission);
	}

	private function gridQuestion(int $id, string $text, string $name, string $cellType): MockObject {
		return $this->createQuestionEntity([
			'id' => $id,
			'type' => Constants::ANSWER_TYPE_GRID,
			'text' => $text,
			'name' => $name,
			'extraSettings' => ['questionType' => $cellType],
		]);
	}

	public function testSendResolvesGridOptionLabelsInFieldPlaceholders(): void {
		$payload = $this->queueAndCapture(
			[
				$this->emailQuestion(),
				$this->gridQuestion(7, 'Rate these', 'rate', Constants::ANSWER_GRID_TYPE_RADIO),
			],
			[
				$this->makeAnswer(5, 'alice@example.com'),
				// Stored as JSON of option ids: row 21 -> column 25, row 22 -> column 26
				$this->makeAnswer(7, json_encode([21 => 25, 22 => 26])),
			],
			'Rating: {rate}',
			optionsByQuestion: [
				7 => [
					$this->createOption(21, 'Speed', Option::OPTION_TYPE_ROW),
					$this->createOption(22, 'Price', Option::OPTION_TYPE_ROW),
					$this->createOption(25, 'Good', Option::OPTION_TYPE_COLUMN),
					$this->createOption(26, 'Fair', Option::OPTION_TYPE_COLUMN),
				],
			],
		);

		// Must not emit the raw stored JSON of option ids
		$this->assertSame('Rating: Speed: Good; Price: Fair', $payload['body']);
	}

	public function testSendResolvesCheckboxAndNumberGridCells(): void {
		$payload = $this->queueAndCapture(
			[
				$this->emailQuestion(),
				$this->gridQuestion(7, 'Pick any', 'pick', Constants::ANSWER_GRID_TYPE_CHECKBOX),
				$this->gridQuestion(8, 'Give numbers', 'numbers', Constants::ANSWER_GRID_TYPE_NUMBER),
			],
			[
				$this->makeAnswer(5, 'alice@example.com'),
				$this->makeAnswer(7, json_encode([21 => [25, 26]])),
				// A zero is a real number and must not be dropped
				$this->makeAnswer(8, json_encode([41 => [45 => 3, 46 => 0]])),
			],
			'{pick} / {numbers}',
			optionsByQuestion: [
				7 => [
					$this->createOption(21, 'Speed', Option::OPTION_TYPE_ROW),
					$this->createOption(25, 'Good', Option::OPTION_TYPE_COLUMN),
					$this->createOption(26, 'Cheap', Option::OPTION_TYPE_COLUMN),
				],
				8 => [
					$this->createOption(41, 'Speed', Option::OPTION_TYPE_ROW),
					$this->createOption(45, 'Min', Option::OPTION_TYPE_COLUMN),
					$this->createOption(46, 'Max', Option::OPTION_TYPE_COLUMN),
				],
			],
		);

		$this->assertSame('Speed: Good; Cheap / Speed: Min: 3; Max: 0', $payload['body']);
	}

	public function testSendKeepsOptionsLabelledZero(): void {
		$payload = $this->queueAndCapture(
			[
				$this->emailQuestion(),
				$this->gridQuestion(7, 'Rate 0 to 2', 'rate', Constants::ANSWER_GRID_TYPE_RADIO),
			],
			[
				$this->makeAnswer(5, 'alice@example.com'),
				$this->makeAnswer(7, json_encode([21 => 25])),
			],
			'{rate}',
			optionsByQuestion: [
				7 => [
					$this->createOption(21, 'Speed', Option::OPTION_TYPE_ROW),
					// A rating scale starting at zero is an ordinary form
					$this->createOption(25, '0', Option::OPTION_TYPE_COLUMN),
				],
			],
		);

		$this->assertSame('Speed: 0', $payload['body']);
	}

	public function testSendResolvesRankingOptionLabels(): void {
		$payload = $this->queueAndCapture(
			[
				$this->emailQuestion(),
				$this->createQuestionEntity([
					'id' => 9,
					'type' => Constants::ANSWER_TYPE_RANKING,
					'text' => 'Rank these',
					'name' => 'rank',
				]),
			],
			[
				$this->makeAnswer(5, 'alice@example.com'),
				// Stored as an ordered JSON list of option ids
				$this->makeAnswer(9, json_encode([31, 29, 30])),
			],
			'{rank}',
			optionsByQuestion: [
				9 => [
					$this->createOption(29, 'Red'),
					$this->createOption(30, 'Green'),
					$this->createOption(31, 'Blue'),
				],
			],
		);

		$this->assertSame('1. Blue, 2. Red, 3. Green', $payload['body']);
	}

	public function testSendKeepsRankingPositionsWhenAnOptionNoLongerResolves(): void {
		$payload = $this->queueAndCapture(
			[
				$this->emailQuestion(),
				$this->createQuestionEntity([
					'id' => 9,
					'type' => Constants::ANSWER_TYPE_RANKING,
					'text' => 'Rank these',
					'name' => 'rank',
				]),
			],
			[
				$this->makeAnswer(5, 'alice@example.com'),
				$this->makeAnswer(9, json_encode([31, 29, 30])),
			],
			'{rank}',
			// Option 29 was deleted after the submission and no longer resolves
			optionsByQuestion: [
				9 => [
					$this->createOption(30, 'Green'),
					$this->createOption(31, 'Blue'),
				],
			],
		);

		// Green stays 3rd; it must not be promoted to 2nd by the missing option
		$this->assertSame('1. Blue, 3. Green', $payload['body']);
	}

	public function testSendFallsBackToTheDashForAnswersThatAreNotAJsonStructure(): void {
		$payload = $this->queueAndCapture(
			[
				$this->emailQuestion(),
				$this->gridQuestion(7, 'Broken grid', 'grid', Constants::ANSWER_GRID_TYPE_RADIO),
				$this->createQuestionEntity([
					'id' => 9,
					'type' => Constants::ANSWER_TYPE_RANKING,
					'text' => 'Broken ranking',
					'name' => 'rank',
				]),
			],
			[
				$this->makeAnswer(5, 'alice@example.com'),
				// Corrupt rows that decode to a bare scalar rather than a structure:
				// casting them to an array would silently address real option ids
				$this->makeAnswer(7, '25'),
				$this->makeAnswer(9, '25'),
			],
			'{grid} / {rank}',
			optionsByQuestion: [
				7 => [
					$this->createOption(21, 'Speed', Option::OPTION_TYPE_ROW),
					$this->createOption(25, 'Good', Option::OPTION_TYPE_COLUMN),
				],
				9 => [$this->createOption(25, 'Blue')],
			],
		);

		$this->assertSame('— / —', $payload['body']);
	}

	public function testSendNeverExpandsPlaceholdersComingFromAnswers(): void {
		$payload = $this->queueAndCapture(
			[
				$this->createQuestionEntity([
					'id' => 3,
					'type' => Constants::ANSWER_TYPE_SHORT,
					'text' => 'Your name',
					'name' => 'name',
				]),
				$this->emailQuestion(),
			],
			[
				// A respondent typing another field's placeholder must not have it expanded
				$this->makeAnswer(3, '{email}'),
				$this->makeAnswer(5, 'alice@example.com'),
			],
			'Dear {name}, thanks.',
		);

		$this->assertSame('Dear {email}, thanks.', $payload['body']);
	}

	public function testSendKeepsBlankAnswersInFieldPlaceholders(): void {
		$payload = $this->queueAndCapture(
			[
				$this->createQuestionEntity([
					'id' => 3,
					'type' => Constants::ANSWER_TYPE_SHORT,
					'text' => 'Your name',
					'name' => 'name',
				]),
				$this->emailQuestion(),
			],
			[
				$this->makeAnswer(3, '   '),
				$this->makeAnswer(5, 'alice@example.com'),
			],
			'Name:[{name}]',
		);

		// The stored answer is rendered as-is; the placeholder is not left unreplaced
		$this->assertSame('Name:[   ]', $payload['body']);
	}

	public function testSendDoesNothingWhenRateLimitExceeded(): void {
		$form = Form::fromParams([
			'id' => 1,
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => 5,
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->configService->method('getConfirmationEmailRateLimit')->willReturn(3);
		$this->questionMapper->method('findByForm')->willReturn([
			$this->createQuestionEntity([
				'id' => 5,
				'type' => Constants::ANSWER_TYPE_SHORT,
				'extraSettings' => ['validationType' => 'email'],
			]),
		]);
		$this->answerMapper->method('findBySubmission')->willReturn([
			$this->makeAnswer(5, 'user@example.com'),
		]);
		$this->emailValidator->method('isValid')->willReturn(true);

		$this->cache->method('add')->willReturn(false);
		$this->cache->method('inc')->willReturn(4); // exceeds limit of 3

		$this->jobList->expects($this->never())->method('add');

		$this->service->send($form, $submission);
	}

	public function testSendSkipsRateLimitWhenCacheIsNotIMemcache(): void {
		$plainCache = $this->createMock(ICache::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('createDistributed')->willReturn($plainCache);

		$service = new ConfirmationEmailService(
			$this->configService,
			$this->answerMapper,
			$this->questionMapper,
			$this->optionMapper,
			$this->emailValidator,
			$this->jobList,
			$this->cacheFactory,
			$this->l10n,
			$this->logger,
		);

		$form = Form::fromParams([
			'id' => 1,
			'title' => 'Test',
			'confirmationEmailEnabled' => true,
			'confirmationEmailQuestionId' => 5,
		]);
		$submission = new Submission();
		$submission->setId(10);

		$this->configService->method('getAllowConfirmationEmail')->willReturn(true);
		$this->questionMapper->method('findByForm')->willReturn([
			$this->createQuestionEntity([
				'id' => 5,
				'type' => Constants::ANSWER_TYPE_SHORT,
				'extraSettings' => ['validationType' => 'email'],
			]),
		]);
		$this->answerMapper->method('findBySubmission')->willReturn([
			$this->makeAnswer(5, 'user@example.com'),
		]);
		$this->emailValidator->method('isValid')->willReturn(true);

		$this->jobList->expects($this->once())->method('add');

		$service->send($form, $submission);
	}

	public function testValidateRecipientQuestionIdAllowsNull(): void {
		$form = new Form();
		$this->service->validateRecipientQuestionId($form, null);
		$this->assertTrue(true);
	}

	public function testValidateRecipientQuestionIdRejectsNonInt(): void {
		$form = new Form();
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateRecipientQuestionId($form, '7');
	}

	public function testValidateRecipientQuestionIdRejectsNotFoundQuestion(): void {
		$form = new Form();
		$this->questionMapper->method('findById')
			->willThrowException(new DoesNotExistException(''));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateRecipientQuestionId($form, 7);
	}

	public function testValidateRecipientQuestionIdRejectsMismatchedForm(): void {
		$form = new Form();
		$form->setId(1);

		$question = new Question();
		$question->setFormId(2);
		$question->setOrder(1);
		$question->setType(Constants::ANSWER_TYPE_SHORT);
		$question->setExtraSettings(['validationType' => 'email']);

		$this->questionMapper->method('findById')->willReturn($question);
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateRecipientQuestionId($form, 7);
	}

	public function testValidateRecipientQuestionIdRejectsDeletedQuestion(): void {
		$form = new Form();
		$form->setId(1);

		$question = new Question();
		$question->setFormId(1);
		$question->setOrder(0);
		$question->setType(Constants::ANSWER_TYPE_SHORT);
		$question->setExtraSettings(['validationType' => 'email']);

		$this->questionMapper->method('findById')->willReturn($question);
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateRecipientQuestionId($form, 7);
	}

	public function testValidateRecipientQuestionIdRejectsNonEmailQuestion(): void {
		$form = new Form();
		$form->setId(1);

		$question = new Question();
		$question->setFormId(1);
		$question->setOrder(1);
		$question->setType(Constants::ANSWER_TYPE_LONG);

		$this->questionMapper->method('findById')->willReturn($question);
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateRecipientQuestionId($form, 7);
	}

	public function testValidateRecipientQuestionIdAllowsValidQuestion(): void {
		$form = new Form();
		$form->setId(1);

		$question = new Question();
		$question->setFormId(1);
		$question->setOrder(1);
		$question->setType(Constants::ANSWER_TYPE_SHORT);
		$question->setExtraSettings(['validationType' => 'email']);

		$this->questionMapper->method('findById')->willReturn($question);
		$this->service->validateRecipientQuestionId($form, 7);
		$this->assertTrue(true);
	}
}
