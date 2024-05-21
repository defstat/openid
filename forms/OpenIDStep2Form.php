<?php

/**
 * @file forms/OpenIDStep2Form.php
 *
 * Copyright (c) 2020 Leibniz Institute for Psychology Information (https://leibniz-psychology.org/)
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class OpenIDStep2Form
 *
 * @ingroup plugins_generic_openid
 *
 * @brief Form class for the second step which is needed if no local user was found with the OpenID identifier
 */

namespace APP\plugins\generic\openid\forms;

use APP\core\Application;
use APP\plugins\generic\openid\enums\OpenIDProvider;
use APP\plugins\generic\openid\handler\OpenIDHandler;
use APP\plugins\generic\openid\OpenIDPlugin;
use APP\template\TemplateManager;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\user\form\UserFormHelper;
use PKP\user\InterestManager;
use PKP\user\User;
use Sokil\IsoCodes\IsoCodesFactory;
use PKP\form\Form;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorEmail;
use PKP\form\validation\FormValidatorCustom;
use APP\facades\Repo;
use PKP\facades\Locale;

/**
 * This file is part of OpenID Authentication Plugin (https://github.com/leibniz-psychology/pkp-openid).
 *
 * OpenID Authentication Plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenID Authentication Plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OpenID Authentication Plugin.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Copyright (c) 2020 Leibniz Institute for Psychology Information (https://leibniz-psychology.org/)
 *
 * @file plugins/generic/openid/forms/OpenIDStep2Form.inc.php
 * @ingroup plugins_generic_openid
 * @brief Form class for the second step which is needed if no local user was found with the OpenID identifier
 */
class OpenIDStep2Form extends Form
{
    private $contextId;

    /**
     * OpenIDStep2Form constructor.
     */
    function __construct(private OpenIDPlugin $plugin, private ?OpenIDProvider $selectedProvider = null, private $claims = [])
    {
        $context = Application::get()->getRequest()->getContext();
        $this->contextId = ($context == null) ? PKPApplication::CONTEXT_SITE : $context->getId();
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        parent::__construct($plugin->getTemplateResource('authStep2.tpl'));
    }

    /**
     * @copydoc Form::fetch()
     */
    function fetch($request, $template = null, $display = false)
    {
        $settings = OpenIDHandler::getOpenIDSettings($this->plugin, $this->contextId);

        $isoCodes = new IsoCodesFactory();
        $countries = [];
        foreach ($isoCodes->getCountries() as $country) {
            $countries[$country->getAlpha2()] = $country->getLocalName();
        }
        asort($countries);

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'disableConnect' => $settings['disableConnect'],
            'countries' => $countries
        ]);

        $userFormHelper = new UserFormHelper();
        $userFormHelper->assignRoleContent($templateMgr, $request);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::initData()
     */
    function initData()
    {
        if (is_array($this->claims) && !empty($this->claims)) {
            // generate username if username is orcid id
            if ($this->claims['username'] ?? false) {
                if (preg_match('/\d{4}-\d{4}-\d{4}-\d{4}/', $this->claims['username'])) {
                    $given = $this->claims['given_name'] ?? '';
                    $family = $this->claims['family_name'] ?? '';
                    $this->claims['username'] = mb_strtolower($given.$family, 'UTF-8');
                }
            }

            foreach ($this->claims as $key => $value) {
                // Check if the value is a string to prevent errors
                if (is_string($value)) {
                    $this->claims[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                }
            }
            
            $this->_data = [
                'selectedProvider' => $this->selectedProvider->value,
                'oauthId' => OpenIDHandler::encryptOrDecrypt($this->plugin, $this->contextId, $this->claims['id']),
                'username' => $this->claims['username'],
                'givenName' => $this->claims['given_name'],
                'familyName' => $this->claims['family_name'],
                'email' => $this->claims['email'],
                'userGroupIds' => [],
            ];
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    function readInputData()
    {
        parent::readInputData();
        $this->readUserVars(
            [
                'selectedProvider',
                'oauthId',
                'username',
                'email',
                'givenName',
                'familyName',
                'affiliation',
                'country',
                'privacyConsent',
                'emailConsent',
                'register',
                'connect',
                'usernameLogin',
                'passwordLogin',
                'readerGroup',
                'reviewerGroup',
                'interests',
            ]
        );
        // Collect the specified user group IDs into a single piece of data
        $this->setData(
            'userGroupIds',
            array_merge(
                array_keys((array)$this->getData('readerGroup')),
                array_keys((array)$this->getData('reviewerGroup'))
            )
        );
    }

    /**
     * @copydoc Form::validate()
     */
    function validate($callHooks = true)
    {
        $register = is_string($this->getData('register'));
        $connect = is_string($this->getData('connect'));
        if ($register) {
            $this->_data['returnTo'] = "register";
            $this->addCheck(new FormValidator($this, 'username', 'required', 'plugins.generic.openid.form.error.username.required'));
            $this->addCheck(
                new FormValidatorCustom(
                    $this, 'username', 'required', 'plugins.generic.openid.form.error.usernameExists', function ($username) {
                        $user = Repo::user()->getByUsername($username, true);
                        return !isset($user);
                    }
                )
            );

            $this->addCheck(new FormValidator($this, 'givenName', 'required', 'plugins.generic.openid.form.error.givenName.required'));
            $this->addCheck(new FormValidator($this, 'familyName', 'required', 'plugins.generic.openid.form.error.familyName.required'));
            $this->addCheck(new FormValidator($this, 'country', 'required', 'plugins.generic.openid.form.error.country.required'));
            $this->addCheck(new FormValidator($this, 'affiliation', 'required', 'plugins.generic.openid.form.error.affiliation.required'));
            $this->addCheck(new FormValidatorEmail($this, 'email', 'required', 'plugins.generic.openid.form.error.email.required'));
            $this->addCheck(
                new FormValidatorCustom(
                    $this, 'email', 'required', 'plugins.generic.openid.form.error.emailExists', function ($email) {
                        $user = Repo::user()->getByEmail($email, true);
                        return !isset($user);
                    }
                )
            );
            $context = Application::get()->getRequest()->getContext();
            if ($context && $context->getData('privacyStatement')) {
                $this->addCheck(new FormValidator($this, 'privacyConsent', 'required', 'plugins.generic.openid.form.error.privacyConsent.required'));
            }
        } elseif ($connect) {
            $this->_data['returnTo'] = "connect";
            $this->addCheck(new FormValidator($this, 'usernameLogin', 'required', 'plugins.generic.openid.form.error.usernameOrEmail.required'));
            $this->addCheck(new FormValidator($this, 'passwordLogin', 'required', 'plugins.generic.openid.form.error.password.required'));
            $username = $this->getData('usernameLogin');
            $password = $this->getData('passwordLogin');
            $user = Repo::user()->getByUsername($username, true);
            if (!isset($user)) {
                $user = Repo::user()->getByEmail($username, true);
            }
            if (!isset($user)) {
                $this->addError('usernameLogin', __('plugins.generic.openid.form.error.user.not.found'));
            } else {
                $valid = Validation::verifyPassword($user->getUsername(), $password, $user->getPassword(), $rehash);
                if (!$valid) {
                    $this->addError('passwordLogin', __('plugins.generic.openid.form.error.invalid.credentials'));
                }
            }
        }

        return parent::validate($callHooks);
    }

    /**
     * @copydoc Form::execute()
     */
    function execute(...$functionArgs)
    {
        $register = is_string($this->getData('register'));
        $connect = is_string($this->getData('connect'));
        $oauthId = $this->getData('oauthId');
        $selectedProvider = OpenIDProvider::tryFrom($this->getData('selectedProvider'));

        $result = false;

        if (!empty($oauthId) && !empty($selectedProvider)) {
            $oauthId = OpenIDHandler::encryptOrDecrypt($this->plugin, $this->contextId, $oauthId, false);
            // prevent saving one openid:ident to multiple accounts
            $userIds = Repo::user()->getIdsBySetting(OpenIDHandler::getOpenIDUserSetting($selectedProvider), $oauthId);
            if ($userIds->isEmpty()) {
                $userIds = Repo::user()->getIdsBySetting(OpenIDHandler::getOpenIDUserSetting($selectedProvider), hash('sha256', $oauthId));
            }
            if ($userIds->isEmpty()) {
                if ($register) {
                    $user = $this->_registerUser();
                    if (isset($user)) {
                        if ($selectedProvider == OpenIDProvider::ORCID) {
                            $user->setOrcid($oauthId);
                        }
                        $user->setData(OpenIDHandler::getOpenIDUserSetting($selectedProvider), $oauthId);
                        
                        Repo::user()->edit($user);
                        $result = true;
                    }
                } elseif ($connect) {
                    $payload = [
                        'given_name' => $this->getData('givenName'), 
                        'family_name' => $this->getData('familyName'), 
                        'id' => $oauthId
                    ];
                    $username = $this->getData('usernameLogin');
                    $password = $this->getData('passwordLogin');
                    $user = Repo::user()->getByUsername($username, true);
                    if (!isset($user)) {
                        $user = Repo::user()->getByEmail($username, true);
                    }
                    if (isset($user) && Validation::verifyPassword($user->getUsername(), $password, $user->getPassword(), $rehash)) {
                        $result = true;
                    }
                }
                if ($result && isset($user)) {
                    OpenIDHandler::updateUserDetails($this->plugin, isset($payload) ? $payload : null, $user, Application::get()->getRequest(), $selectedProvider, true);
                    Validation::registerUserSession($user, $reason);
                }
            }
        }
        parent::execute(...$functionArgs);

        return $result;
    }


    /**
     * This function registers a new User if no user exists with the given username, email or openid::{provider_name}!
     */
    private function _registerUser(): ?User
    {
        $user = Repo::user()->newDataObject();
        $user->setUsername($this->getData('username'));
        $request = Application::get()->getRequest();
        $site = $request->getSite();
        $sitePrimaryLocale = $site->getPrimaryLocale();
        $currentLocale = Locale::getLocale();
        $user->setGivenName($this->getData('givenName'), $currentLocale);
        $user->setFamilyName($this->getData('familyName'), $currentLocale);
        $user->setEmail($this->getData('email'));
        $user->setCountry($this->getData('country'));
        $user->setAffiliation($this->getData('affiliation'), $currentLocale);
        if ($sitePrimaryLocale != $currentLocale) {
            $user->setGivenName($this->getData('givenName'), $sitePrimaryLocale);
            $user->setFamilyName($this->getData('familyName'), $sitePrimaryLocale);
            $user->setAffiliation($this->getData('affiliation'), $sitePrimaryLocale);
        }
        $user->setDateRegistered(Core::getCurrentDate());
        $user->setInlineHelp(1);
        $user->setPassword(Validation::encryptCredentials($this->getData('username'), openssl_random_pseudo_bytes(16)));
        Repo::user()->add($user);
        if ($user->getId()) {
            // Insert the user interests
            $interestManager = new InterestManager();
            $interestManager->setInterestsForUser($user, $this->getData('interests'));

            // Save the selected roles or assign the Reader role if none selected
            if ($request->getContext() && !$this->getData('reviewerGroup')) {
                $defaultReaderGroups = Repo::userGroup()
                    ->getCollector()
                    ->filterByIsDefault(true)
                    ->filterByContextIds([$request->getContext()->getId()])
                    ->filterByRoleIds([Role::ROLE_ID_READER])
                    ->getMany();
                
                if ($defaultReaderGroups->isNotEmpty()) {
                    $defaultReaderGroup = $defaultReaderGroups->first();
                    Repo::userGroup()->assignUserToGroup($user->getId(), $defaultReaderGroup->getId());
                }
            } else {
                $userFormHelper = new UserFormHelper();
                $userFormHelper->saveRoleContent($this, $user);
            }
        } else {
            $user = null;
        }

        return $user;
    }
}
