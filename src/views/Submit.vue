<!--
 - @copyright Copyright (c) 2020 John Molakvoæ <skjnldsv@protonmail.com>
 -
 - @author John Molakvoæ <skjnldsv@protonmail.com>
 - @author Michael Schmidmaier
 -
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -
 -->

<template>
	<NcAppContent v-if="isLoadingForm">
		<NcEmptyContent class="forms-emptycontent"
			:name="t('forms', 'Loading {title} …', { title: form.title })">
			<template #icon>
				<NcLoadingIcon :size="64" />
			</template>
		</NcEmptyContent>
	</NcAppContent>

	<NcAppContent v-else :class="{'app-content--public': publicView}">
		<TopBar v-if="!publicView"
			:permissions="form?.permissions"
			:sidebar-opened="sidebarOpened"
			@update:sidebarOpened="onSidebarChange"
			@share-form="onShareForm" />

		<!-- Forms title & description-->
		<header>
			<h2 ref="title" class="form-title" dir="auto">
				{{ formTitle }}
			</h2>
			<!-- eslint-disable vue/no-v-html -->
			<div v-if="!loading && !success && !!formDescription"
				class="form-desc"
				dir="auto"
				v-html="formDescription" />
			<!-- eslint-enable vue/no-v-html -->
			<!-- Show expiration message-->
			<p v-if="form.expires && form.showExpiration" class="info-message">
				{{ expirationMessage }}
			</p>
			<!-- Generate form information message-->
			<p v-if="infoMessage" class="info-message">
				{{ infoMessage }}
			</p>
		</header>

		<NcEmptyContent v-if="loading"
			class="forms-emptycontent"
			:name="t('forms', 'Submitting form …')">
			<template #icon>
				<NcLoadingIcon :size="64" />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="success || !form.canSubmit"
			class="forms-emptycontent"
			:name="t('forms', 'Thank you for completing the form!')"
			:description="form.submissionMessage">
			<template #icon>
				<NcIconSvgWrapper :svg="IconCheckSvg" :size="64" />
			</template>
			<template v-if="submissionMessageHTML" #description>
				<!-- eslint-disable-next-line vue/no-v-html -->
				<p class="submission-message" v-html="submissionMessageHTML" />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="isExpired"
			class="forms-emptycontent"
			:name="t('forms', 'Form expired')"
			:description="t('forms', 'This form has expired and is no longer taking answers')">
			<template #icon>
				<NcIconSvgWrapper :svg="IconCheckSvg" size="64" />
			</template>
		</NcEmptyContent>

		<!-- Questions list -->
		<form v-else
			ref="form"
			@submit.prevent="onSubmit">
			<ul>
				<Questions :is="answerTypes[question.type].component"
					v-for="(question, index) in validQuestions"
					ref="questions"
					:key="question.id"
					:read-only="true"
					:answer-type="answerTypes[question.type]"
					:index="index + 1"
					:max-string-lengths="maxStringLengths"
					:values="answers[question.id]"
					v-bind="question"
					@keydown.enter="onKeydownEnter"
					@keydown.ctrl.enter="onKeydownCtrlEnter"
					@update:values="(values) => onUpdate(question, values)" />
			</ul>
			<input ref="submitButton"
				class="primary"
				type="submit"
				:value="t('forms', 'Submit')"
				:disabled="loading"
				:aria-label="t('forms', 'Submit form')">
		</form>

		<!-- Confirmation dialog if form is empty submitted -->
		<NcDialog :open.sync="showConfirmEmptyModal"
			:name="t('forms', 'Confirm submit')"
			:message="t('forms', 'Are you sure you want to submit an empty form?')"
			:buttons="confirmEmptyModalButtons" />
	</NcAppContent>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'

import axios from '@nextcloud/axios'
import moment from '@nextcloud/moment'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcIconSvgWrapper from '@nextcloud/vue/dist/Components/NcIconSvgWrapper.js'

import IconCancelSvg from '@mdi/svg/svg/cancel.svg?raw'
import IconCheckSvg from '@mdi/svg/svg/check.svg?raw'

import answerTypes from '../models/AnswerTypes.js'
import logger from '../utils/Logger.js'

import Question from '../components/Questions/Question.vue'
import QuestionLong from '../components/Questions/QuestionLong.vue'
import QuestionShort from '../components/Questions/QuestionShort.vue'
import QuestionMultiple from '../components/Questions/QuestionMultiple.vue'
import TopBar from '../components/TopBar.vue'
import SetWindowTitle from '../utils/SetWindowTitle.js'
import ViewsMixin from '../mixins/ViewsMixin.js'

export default {
	name: 'Submit',

	components: {
		NcAppContent,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcIconSvgWrapper,
		Question,
		QuestionLong,
		QuestionShort,
		QuestionMultiple,
		TopBar,
	},

	mixins: [ViewsMixin],

	/*
	 * This is used to confirm that the user wants to leave the page
	 * if the form is unsubmitted.
	 */
	beforeRouteUpdate(to, from, next) {
		// This navigation guard is called when the route parameters changed (e.g. form hash)
		// continue with the navigation if there are no changes or the user confirms to leave the form
		if (this.confirmLeaveForm()) {
			next()
		} else {
			// Otherwise cancel the navigation
			next(false)
		}
	},

	beforeRouteLeave(to, from, next) {
		// This navigation guard is called when the route changed and a new view should be shown
		// continue with the navigation if there are no changes or the user confirms to leave the form
		if (this.confirmLeaveForm()) {
			next()
		} else {
			// Otherwise cancel the navigation
			next(false)
		}
	},

	props: {
		isLoggedIn: {
			type: Boolean,
			required: false,
			default: true,
		},
		shareHash: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			maxStringLengths: loadState('forms', 'maxStringLengths'),
			answerTypes,
			/**
			 * Mapping of questionId => answers
			 * @type {Record<number, string[]>}
			 */
			answers: {},
			loading: false,
			success: false,
			/** Submit state of the form, true if changes are currently submitted */
			submitForm: false,
			showConfirmEmptyModal: false,
		}
	},

	computed: {
		IconCheckSvg() {
			return IconCheckSvg
		},

		validQuestions() {
			return this.form.questions.filter(question => {
				// All questions must have a valid title
				if (question.text?.trim() === '') {
					return false
				}

				// If specific conditions provided, test against them
				if ('validate' in answerTypes[question.type]) {
					return answerTypes[question.type].validate(question)
				}
				return true
			})
		},

		isRequiredUsed() {
			return this.form.questions.reduce((isUsed, question) => isUsed || question.isRequired, false)
		},

		/**
		 * Check if form is expired
		 */
		isExpired() {
			return this.form.expires && moment().unix() > this.form.expires
		},

		infoMessage() {
			let message = ''
			if (this.form.isAnonymous) {
				message += t('forms', 'Responses are anonymous.')
			}
			if (!this.form.isAnonymous && this.isLoggedIn) {
				message += t('forms', 'Responses are connected to your account.')
			}
			if (this.isRequiredUsed) {
				message += ' ' + t('forms', 'An asterisk (*) indicates mandatory questions.')
			}

			return message
		},

		/**
		 * Rendered HTML of the custom submission message
		 */
		submissionMessageHTML() {
			if (this.form.submissionMessage && (this.success || !this.form.canSubmit)) {
				return this.markdownit.render(this.form.submissionMessage)
			}
			return ''
		},

		expirationMessage() {
			const relativeDate = moment(this.form.expires, 'X').fromNow()
			if (this.isExpired) {
				return t('forms', 'Expired {relativeDate}.', { relativeDate })
			}
			return t('forms', 'Expires {relativeDate}.', { relativeDate })
		},

		/**
		 * Buttons for the "confirm submit empty form" dialog
		 */
		confirmEmptyModalButtons() {
			return [{
				label: t('forms', 'Abort'),
				icon: IconCancelSvg,
				callback: () => {},
			}, {
				label: t('forms', 'Submit'),
				icon: IconCheckSvg,
				type: 'primary',
				callback: () => this.onConfirmedSubmit(),
			}]
		},
	},

	watch: {
		hash() {
			// If public view, abort. Should normally not occur.
			if (this.publicView) {
				logger.error('Hash changed on public View. Aborting.')
				return
			}
			this.resetData()
			// Fetch full form on change
			this.fetchFullForm(this.form.id)
			this.initFromLocalStorage()
			SetWindowTitle(this.formTitle)
		},
	},

	beforeDestroy() {
		window.removeEventListener('beforeunload', this.beforeWindowUnload)
	},
	created() {
		window.addEventListener('beforeunload', this.beforeWindowUnload)
	},

	beforeMount() {
		// Public Views get their form by initial-state from parent. No fetch necessary.
		if (this.publicView) {
			this.isLoadingForm = false
		} else {
			this.fetchFullForm(this.form.id)
		}
		SetWindowTitle(this.formTitle)
		if (this.isLoggedIn) {
			this.initFromLocalStorage()
		}
	},

	methods: {
		/**
		 * Load saved values for current form from LocalStorage
		 * @return {Record<string,any>}
		 */
		getFormValuesFromLocalStorage() {
			const fromLocalStorage = localStorage.getItem(`nextcloud_forms_${this.publicView ? this.shareHash : this.hash}`)
			if (fromLocalStorage) {
				return JSON.parse(fromLocalStorage)
			}
			return null
		},

		/**
		 * Initialize answers from saved state in LocalStorage
		 */
		initFromLocalStorage() {
			const savedState = this.getFormValuesFromLocalStorage()
			if (savedState) {
				for (const [key, answer] of Object.entries(savedState)) {
					const answers = []
					switch (answer?.type) {
					case 'QuestionMultiple':
						answer.value.forEach(num => {
							answers.push(num.toString())
						})
						this.answers[key] = answers
						break
					default:
						this.answers[key] = answer.value
						break
					}
				}
			}
		},

		/**
		 * Save updated answers for question to LocalStorage in case of browser crash / closes / etc
		 * @param {*} question Question to update
		 */
		addFormFieldToLocalStorage(question) {
			if (!this.isLoggedIn) {
				return
			}
			// We make sure the values are updated by the `values.sync` handler
			const state = {
				...(this.getFormValuesFromLocalStorage() ?? {}),
				[`${question.id}`]: {
					value: this.answers[question.id],
					type: answerTypes[question.type].component.name,
				},
			}
			const stringified = JSON.stringify(state)
			localStorage.setItem(`nextcloud_forms_${this.publicView ? this.shareHash : this.hash}`, stringified)
		},

		deleteFormFieldFromLocalStorage() {
			if (!this.isLoggedIn) {
				return
			}
			localStorage.removeItem(`nextcloud_forms_${this.publicView ? this.shareHash : this.hash}`)
		},

		/**
		 * Update answers of a give value
		 * @param {{id: number}} question The question to answer
		 * @param {unknown[]} values The new values
		 */
		 onUpdate(question, values) {
			this.answers = { ...this.answers, [question.id]: values }
			this.addFormFieldToLocalStorage(question)
		},

		/**
		 * On Enter, focus next form-element
		 * Last form element is the submit button, the form submits on enter then
		 *
		 * @param {object} event The fired event.
		 */
		onKeydownEnter(event) {
			const formInputs = Array.from(this.$refs.form)
			const sourceInputIndex = formInputs.findIndex(input => input === event.originalTarget)

			// Focus next form element
			formInputs[sourceInputIndex + 1].focus()
		},

		/**
		 * Ctrl+Enter typically fires submit on forms.
		 * Some inputs do automatically, while some need explicit handling
		 */
		onKeydownCtrlEnter() {
			// Using button-click event to not bypass validity-checks and use our specified behaviour
			this.$refs.submitButton.click()
		},

		/*
		 * Methods for catching unwanted unload events
		 */
		beforeWindowUnload(e) {
			if (!this.confirmLeaveForm()) {
				// Cancel the window unload event
				e.preventDefault()
				e.returnValue = ''
			}
		},

		/**
		Check if the form contains unsaved changes, returns true if the the form can be leaved safely, false if the navigation should be canceled.
		 */
		confirmLeaveForm() {
			return (
				this.submitForm
				|| Object.keys(this.answers).length === 0
				|| confirm(t('forms', 'You have unsaved changes! Do you still want to leave?'))
			)
		},

		/**
		 * Submit the form after the browser validated it 🚀 or show confirmation modal if empty
		 */
		onSubmit() {
			// in case no answer is set or all are empty show the confirmation dialog
			if (Object.keys(this.answers).length === 0 || Object.values(this.answers).every((answers) => answers.length === 0)) {
				this.showConfirmEmptyModal = true
			} else {
				// otherwise do the real submit
				this.onConfirmedSubmit()
			}
		},

		/**
		 * Handle the real submit of the form, this is only called if the form is not empty or user confirmed to submit
		 */
		async onConfirmedSubmit() {
			this.showConfirmEmptyModal = false
			this.loading = true

			try {
				await axios.post(generateOcsUrl('apps/forms/api/v2.2/submission/insert'), {
					formId: this.form.id,
					answers: this.answers,
					shareHash: this.shareHash,
				})
				this.submitForm = true
				this.success = true
				this.deleteFormFieldFromLocalStorage()
				emit('forms:last-updated:set', this.form.id)
			} catch (error) {
				logger.error('Error while submitting the form', { error })
				showError(t('forms', 'There was an error submitting the form'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset View-Data
		 */
		resetData() {
			this.answers = {}
			this.loading = false
			this.success = false
			this.submitForm = false
		},
	},

}
</script>
<style lang="scss" scoped>
@import '../scssmixins/markdownOutput';

.forms-emptycontent {
	height: 100%;
}
.app-content {
	display: flex;
	align-items: center;
	flex-direction: column;

	&--public {
		// Compensate top-padding for missing topbar
		padding-block-start: 50px;
	}

	header,
	form {
		width: 100%;
		max-width: 750px;
		display: flex;
		flex-direction: column;
	}

	// Title & description header
	header {
		margin-block-end: 24px;
		margin-inline-start: 56px;

		.form-title,
		.form-desc,
		.info-message {
			width: calc(100% - 56px); // margin of header, needed if screen is < 806px (max-width + margin-left)
			font-size: 100%;
			padding-block: 0px;
			padding-inline: 16px;
			border: none;
		}
		.form-title {
			font-size: 28px;
			font-weight: bold;
			color: var(--color-main-text);
			line-height: 34px;
			min-height: 36px;
			margin-block: 32px;
			margin-inline: 0;
			padding-block-end: 4px;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		.form-desc {
			line-height: 22px;
			padding-block-end: 20px;
			resize: none;
			min-height: 42px;
			color: var(--color-main-text);

			@include markdown-output;
		}

		.info-message {
			padding-block-end: 20px;
			margin-block-start: 4px;
			resize: none;
			color: var(--color-text-maxcontrast);
		}
	}

	.submission-message {
		@include markdown-output;
		text-align: center;
	}

	form {
		.question {
			// Less padding needed as submit view does not have drag handles
			padding-inline-start: 44px;
		}

		input[type=submit] {
			align-self: flex-end;
			margin: 5px;
			margin-block-end: 160px;
			padding-block: 10px;
			padding-inline: 20px;
		}
	}
}
</style>
