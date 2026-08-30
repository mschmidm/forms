<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Forms\Service;

use OCA\Forms\BackgroundJob\SendConfirmationMailJob;
use OCA\Forms\Constants;
use OCA\Forms\Db\AnswerMapper;
use OCA\Forms\Db\Form;
use OCA\Forms\Db\Option;
use OCA\Forms\Db\OptionMapper;
use OCA\Forms\Db\Question;
use OCA\Forms\Db\QuestionMapper;
use OCA\Forms\Db\Submission;
use OCP\AppFramework\Db\IMapperException;
use OCP\BackgroundJob\IJobList;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IMemcache;
use OCP\Mail\IEmailValidator;
use Psr\Log\LoggerInterface;

class ConfirmationEmailService {
	private const RATE_LIMIT_TTL = 86400; // 24 hours

	/** Shown when an answer has no resolvable value */
	private const EMPTY_ANSWER = '—';

	private readonly ICache $rateLimitCache;

	public function __construct(
		private readonly ConfigService $configService,
		private readonly AnswerMapper $answerMapper,
		private readonly QuestionMapper $questionMapper,
		private readonly OptionMapper $optionMapper,
		private readonly IEmailValidator $emailValidator,
		private readonly IJobList $jobList,
		ICacheFactory $cacheFactory,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		$this->rateLimitCache = $cacheFactory->createDistributed('forms_confirmation_email');
	}

	public function send(Form $form, Submission $submission): void {
		if (!$form->getConfirmationEmailEnabled()) {
			return;
		}

		if (!$this->configService->getAllowConfirmationEmail()) {
			$this->logger->debug('Confirmation email feature is disabled by administrator', [
				'formId' => $form->getId(),
			]);
			return;
		}

		$questions = $this->loadQuestions($form->getId());
		$answerMap = $this->buildAnswerMap($submission);

		$recipientQuestion = $this->findRecipientQuestion($form, $questions);
		if ($recipientQuestion === null) {
			if ($form->getConfirmationEmailQuestionId() !== null) {
				$this->logger->debug('Configured confirmation email recipient question is not a valid email question', [
					'formId' => $form->getId(),
					'submissionId' => $submission->getId(),
					'configuredQuestionId' => $form->getConfirmationEmailQuestionId(),
				]);
			}
			return;
		}

		$recipientEmail = $answerMap[$recipientQuestion['id']][0] ?? null;
		if ($recipientEmail === null || !$this->emailValidator->isValid($recipientEmail)) {
			$this->logger->debug('No valid email address found in submission for confirmation email', [
				'formId' => $form->getId(),
				'submissionId' => $submission->getId(),
			]);
			return;
		}

		if (!$this->checkRateLimit($recipientEmail, $form->getId(), $submission->getId())) {
			return;
		}

		[$subject, $body] = $this->buildEmailContent($form, $questions, $answerMap);

		$this->jobList->add(SendConfirmationMailJob::class, [
			'recipient' => $recipientEmail,
			'subject' => $subject,
			'body' => $body,
			'formId' => $form->getId(),
			'submissionId' => $submission->getId(),
		]);
		$this->logger->debug('Confirmation email queued', [
			'formId' => $form->getId(),
			'submissionId' => $submission->getId(),
		]);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function validateRecipientQuestionId(Form $form, mixed $recipientId): void {
		if ($recipientId === null) {
			return;
		}

		if (!is_int($recipientId)) {
			throw new \InvalidArgumentException('Invalid confirmationEmailQuestionId');
		}

		try {
			$question = $this->questionMapper->findById($recipientId);
		} catch (IMapperException $e) {
			throw new \InvalidArgumentException('Invalid confirmationEmailQuestionId', previous: $e);
		}

		if ($question->getFormId() !== $form->getId()
			|| $question->getOrder() === 0
			|| !$question->isEmailType()) {
			throw new \InvalidArgumentException('Invalid confirmationEmailQuestionId');
		}
	}

	/**
	 * Load questions for a form. Skips options and file-type processing not needed for email.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function loadQuestions(int $formId): array {
		$questions = [];
		try {
			foreach ($this->questionMapper->findByForm($formId) as $entity) {
				$questions[] = $entity->read();
			}
		} catch (\Exception $e) {
			$this->logger->debug('Failed to load questions for confirmation email placeholder substitution', [
				'formId' => $formId,
				'exception' => $e,
			]);
		}
		return $questions;
	}

	/**
	 * @return array<int, list<string>>
	 */
	private function buildAnswerMap(Submission $submission): array {
		$map = [];
		foreach ($this->answerMapper->findBySubmission($submission->getId()) as $answer) {
			// The answer text column is nullable, therefore cast like Answer::read()
			$map[$answer->getQuestionId()][] = (string)$answer->getText();
		}
		return $map;
	}

	/**
	 * @param list<array<string, mixed>> $questions
	 * @param array<int, list<string>> $answerMap
	 * @return array{string, string}
	 */
	private function buildEmailContent(Form $form, array $questions, array $answerMap): array {
		$subject = $form->getConfirmationEmailSubject();
		$body = $form->getConfirmationEmailBody();

		if (empty($subject)) {
			$subject = $this->l10n->t('Thank you for your submission');
		}
		if (empty($body)) {
			$body = $this->l10n->t('Thank you for submitting the form "%s".', [$form->getTitle()]);
		}

		$replacements = [
			'{formTitle}' => $form->getTitle(),
			'{formDescription}' => $form->getDescription() ?? '',
		];

		foreach ($questions as $question) {
			$fieldKey = !empty($question['name'] ?? '') ? $question['name'] : ($question['text'] ?? '');
			$fieldKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $fieldKey));
			$answers = $answerMap[$question['id']] ?? [];
			if ($fieldKey === '' || $answers === []) {
				continue;
			}
			$placeholder = '{' . $fieldKey . '}';
			if (isset($replacements[$placeholder])) {
				$this->logger->warning('Confirmation email placeholder key collision, skipping duplicate', [
					'formId' => $form->getId(),
					'key' => $fieldKey,
				]);
				continue;
			}
			$replacements[$placeholder] = implode('; ', $this->formatAnswer($question, $answers));
		}

		return [
			$this->replacePlaceholders($subject, $replacements),
			$this->replacePlaceholders($body, $replacements),
		];
	}

	/**
	 * Replace every known placeholder, leaving unknown ones untouched
	 *
	 * @param array<string, string> $replacements
	 */
	private function replacePlaceholders(string $text, array $replacements): string {
		// One non-recursive pass, so a placeholder a respondent typed into their own
		// answer stays literal instead of being expanded
		return preg_replace_callback(
			'/\{[A-Za-z0-9]+\}/',
			static fn (array $matches): string => $replacements[$matches[0]] ?? $matches[0],
			$text
		) ?? $text;
	}

	/**
	 * Render one answer as the lines it occupies, one line per row for a grid
	 *
	 * @param array<string, mixed> $question
	 * @param list<string> $answers
	 * @return list<string>
	 */
	private function formatAnswer(array $question, array $answers): array {
		// Grid and ranking answers are stored as JSON of option ids, so they need resolving
		return match ($question['type'] ?? '') {
			Constants::ANSWER_TYPE_GRID => $this->formatGridRows($question, (string)reset($answers)),
			Constants::ANSWER_TYPE_RANKING => [$this->formatRanking($question, (string)reset($answers))],
			default => [implode('; ', $answers)],
		};
	}

	/**
	 * Render a grid answer as one "row label: value" line per row
	 *
	 * @param array<string, mixed> $question
	 * @return list<string>
	 */
	private function formatGridRows(array $question, string $answerText): array {
		$cells = json_decode($answerText, true);
		if (!is_array($cells)) {
			return [self::EMPTY_ANSWER];
		}

		$extraSettings = (array)($question['extraSettings'] ?? []);
		$cellType = $extraSettings['questionType'] ?? null;

		$options = $this->loadOptions((int)$question['id']);
		$optionTexts = $this->optionTexts($options);
		$rows = array_filter($options, static fn (Option $o): bool => $o->getOptionType() === Option::OPTION_TYPE_ROW);
		$columns = array_filter($options, static fn (Option $o): bool => $o->getOptionType() === Option::OPTION_TYPE_COLUMN);

		$lines = [];
		foreach ($rows as $row) {
			$cell = $cells[$row->getId()] ?? null;

			$value = match ($cellType) {
				Constants::ANSWER_GRID_TYPE_NUMBER => $this->formatGridNumbers((array)$cell, $columns),
				// Radio holds a single option id, checkbox a list of them
				default => implode('; ', $this->optionLabels((array)$cell, $optionTexts)),
			};

			$lines[] = trim((string)$row->getText()) . ': ' . ($value === '' ? self::EMPTY_ANSWER : $value);
		}

		return $lines === [] ? [self::EMPTY_ANSWER] : $lines;
	}

	/**
	 * @param array<mixed> $cell numbers keyed by column id
	 * @param array<Option> $columns
	 */
	private function formatGridNumbers(array $cell, array $columns): string {
		$pairs = [];
		foreach ($columns as $column) {
			$value = $cell[$column->getId()] ?? null;
			if (!is_scalar($value) || trim((string)$value) === '') {
				continue;
			}
			$pairs[] = trim((string)$column->getText()) . ': ' . trim((string)$value);
		}
		return implode('; ', $pairs);
	}

	/**
	 * Render a ranking answer as "1. first, 2. second", in the respondent's order
	 *
	 * @param array<string, mixed> $question
	 */
	private function formatRanking(array $question, string $answerText): string {
		$rankedIds = json_decode($answerText, true);
		if (!is_array($rankedIds)) {
			return self::EMPTY_ANSWER;
		}

		$optionTexts = $this->optionTexts($this->loadOptions((int)$question['id']));

		$parts = [];
		foreach (array_values($rankedIds) as $position => $optionId) {
			// Number by the respondent's own position, so a since-deleted option
			// leaves a gap instead of silently promoting everything below it
			foreach ($this->optionLabels([$optionId], $optionTexts) as $label) {
				$parts[] = ($position + 1) . '. ' . $label;
			}
		}

		return $parts === [] ? self::EMPTY_ANSWER : implode(', ', $parts);
	}

	/**
	 * Map option ids to their labels, skipping ids that no longer resolve
	 *
	 * @param array<mixed> $optionIds
	 * @param array<int, string> $optionTexts
	 * @return list<string>
	 */
	private function optionLabels(array $optionIds, array $optionTexts): array {
		$labels = [];
		foreach ($optionIds as $optionId) {
			// A malformed answer may hold an unusable array key; '' rather than empty() keeps a label of "0"
			if ((is_int($optionId) || is_string($optionId)) && ($optionTexts[$optionId] ?? '') !== '') {
				$labels[] = $optionTexts[$optionId];
			}
		}
		return $labels;
	}

	/**
	 * @param array<Option> $options
	 * @return array<int, string>
	 */
	private function optionTexts(array $options): array {
		$texts = [];
		foreach ($options as $option) {
			$texts[$option->getId()] = trim((string)$option->getText());
		}
		return $texts;
	}

	/**
	 * @return array<Option>
	 */
	private function loadOptions(int $questionId): array {
		try {
			return $this->optionMapper->findByQuestion($questionId);
		} catch (\Exception $e) {
			$this->logger->debug('Failed to load options for confirmation email submission summary', [
				'questionId' => $questionId,
				'exception' => $e,
			]);
			return [];
		}
	}

	/**
	 * @param list<array<string, mixed>> $questions
	 * @return array<string, mixed>|null
	 */
	private function findRecipientQuestion(Form $form, array $questions): ?array {
		$recipientQuestionId = $form->getConfirmationEmailQuestionId();
		if ($recipientQuestionId === null) {
			return null;
		}

		foreach ($questions as $questionData) {
			if (($questionData['id'] ?? null) !== $recipientQuestionId) {
				continue;
			}

			if (Question::checkEmailType(
				$questionData['type'] ?? '',
				(array)($questionData['extraSettings'] ?? [])
			)) {
				return $questionData;
			}

			return null;
		}

		return null;
	}

	private function checkRateLimit(string $email, int $formId, int $submissionId): bool {
		$cacheKey = 'email_rl_' . hash('sha256', $formId . ':' . strtolower($email));

		if (!$this->rateLimitCache instanceof IMemcache) {
			// Atomic increment requires IMemcache; without it we cannot safely count.
			$this->logger->debug('Distributed cache unavailable, skipping confirmation email rate limit', [
				'formId' => $formId,
			]);
			return true;
		}

		$rateLimit = $this->configService->getConfirmationEmailRateLimit();

		if ($this->rateLimitCache->add($cacheKey, 1, self::RATE_LIMIT_TTL)) {
			$count = 1;
		} else {
			$count = $this->rateLimitCache->inc($cacheKey);
			if (!is_int($count)) {
				$this->logger->warning('Failed to increment confirmation email rate limit counter', [
					'formId' => $formId,
					'submissionId' => $submissionId,
				]);
				return false;
			}
		}

		if ($count > $rateLimit) {
			$this->logger->warning('Per-recipient confirmation email rate limit reached', [
				'formId' => $formId,
				'submissionId' => $submissionId,
			]);
			return false;
		}

		return true;
	}
}
