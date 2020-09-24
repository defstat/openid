<?php
require_once 'plugins/generic/oauth/handler/phpseclib/Crypt/Hash.php';
require_once 'plugins/generic/oauth/handler/phpseclib/Crypt/RSA.php';
require_once 'plugins/generic/oauth/handler/phpseclib/Math/BigInteger.php';

use Firebase\JWT\JWT;
use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;


import('classes.handler.Handler');

class OpenIDHandler extends Handler
{
	/**
	 * @param $args
	 * @param $request
	 * @return bool
	 */
	function doAuthentication($args, $request)
	{
		$templateMgr = TemplateManager::getManager($request);
		$context = $request->getContext();
		$plugin = PluginRegistry::getPlugin('generic', KEYCLOAK_PLUGIN_NAME);
		$contextId = ($context == null) ? 0 : $context->getId();
		$settings = json_decode($plugin->getSetting($contextId, 'openIDSettings'), true);
		$selectedProvider = $request->getUserVar('provider');
		$token = $this->_getTokenViaAuthCode($settings['provider'], $request->getUserVar('code'), $selectedProvider);
		$publicKey = $this->_getOpenIDAuthenticationCert($settings['provider'], $selectedProvider);
		$error = false;
		if (isset($token) && isset($publicKey)) {
			$tokenPayload = $this->_validateAndExtractToken($token, $publicKey);
			if (isset($tokenPayload) && is_array($tokenPayload)) {
				$tokenPayload['selectedProvider'] = $selectedProvider;
				$user = $this->_getUserViaKeycloakId($tokenPayload);
				if (!isset($user)) {
					import($plugin->getPluginPath().'/forms/OpenIDStep2Form');
					$regForm = new OpenIDStep2Form($plugin, $tokenPayload);
					$regForm->initData();
					$regForm->display($request);
				} elseif (is_a($user, 'User') && !$user->getDisabled()) {
					Validation::registerUserSession($user, $reason, true);
					$userSettingsDao = DAORegistry::getDAO('UserSettingsDAO');
					$userSettingsDao->updateSetting($user->getId(), 'openid::lastProvider', $selectedProvider, 'string');
					if ($user->hasRole(
						[ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_AUTHOR, ROLE_ID_REVIEWER, ROLE_ID_ASSISTANT],
						$contextId
					)) {
						$request->redirect($context->getPath(), 'submissions');
					} else {
						$request->redirect($context->getPath(), 'user', 'profile', null, $args);
					}
				} elseif ($user->getDisabled()) {
					$error = true;
					$templateMgr->assign('errorType', 'plugins.generic.openid.error.openid.disabled.head');
					$reason = $user->getDisabledReason();
					if ($reason === null) {
						$templateMgr->assign('errorMsg', 'plugins.generic.openid.error.openid.disabled.without');
					} else {
						$templateMgr->assign('errorMsg', 'plugins.generic.openid.error.openid.disabled.with');
						$templateMgr->assign('reason', $reason);
					}
				}
			} else {
				$error = true;
				$templateMgr->assign('errorType', 'plugins.generic.openid.error.openid.cert.head');
				$templateMgr->assign('errorMsg', 'plugins.generic.openid.error.openid.cert.desc');
			}
		} else {
			$error = true;
			$templateMgr->assign('errorType', 'plugins.generic.openid.error.openid.connect.head');
			if (!isset($publicKey)) {
				$templateMgr->assign('errorMsg', 'plugins.generic.openid.error.openid.connect.desc.key');
			} else {
				$templateMgr->assign('errorMsg', 'plugins.generic.openid.error.openid.connect.desc.data');
			}
		}
		if ($error) {
			$supportEmail = $context ? $context->getSetting('supportEmail') : null;
			if ($supportEmail) {
				$templateMgr->assign('supportEmail', $supportEmail);
			}
			$templateMgr->display($plugin->getTemplateResource('error.tpl'));
		}

		return true;
	}

	/**
	 * @param $args
	 * @param $request
	 */
	function registerOrConnect($args, $request)
	{
		$generateApiKey = true;
		if (Validation::isLoggedIn()) {
			$this->setupTemplate($request);
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('pageTitle', 'user.login.registrationComplete');
			$templateMgr->display('frontend/pages/userRegisterComplete.tpl');
		} elseif (!$request->isPost()) {
			$request->redirect(Application::get()->getRequest()->getContext(), 'login');
		} else {
			$plugin = PluginRegistry::getPlugin('generic', KEYCLOAK_PLUGIN_NAME);
			import($plugin->getPluginPath().'/forms/OpenIDStep2Form');
			$regForm = new OpenIDStep2Form($plugin);
			$regForm->readInputData();
			if (!$regForm->validate()) {
				$regForm->display($request);
			} elseif ($regForm->execute($generateApiKey)) {
				$request->redirect(Application::get()->getRequest()->getContext(), 'openid', 'registerOrConnect');
			} else {
				$regForm->addError('', '');
				$regForm->display($request);
			}
		}
	}

	/**
	 * Tries to find a user via OpenID credentials.
	 *
	 * @param array $credentials
	 * @return User|null
	 */
	private function _getUserViaKeycloakId(array $credentials)
	{
		$userDao = DAORegistry::getDAO('UserDAO');
		$user = $userDao->getBySetting('openid::'.$credentials['selectedProvider'], hash('sha256', $credentials['id']));
		if (isset($user) && is_a($user, 'User')) {
			return $user;
		}

		return null;
	}


	/**
	 * This function returns the token data.
	 *
	 * @param array $providerList
	 * @param string $authorizationCode
	 * @param string $selectedProvider
	 * @return array
	 */
	private function _getTokenViaAuthCode(array $providerList, string $authorizationCode, string $selectedProvider)
	{
		$token = null;
		if (isset($providerList) && key_exists($selectedProvider, $providerList)) {
			$settings = $providerList[$selectedProvider];
			$curl = curl_init();
			curl_setopt_array(
				$curl,
				array(
					CURLOPT_URL => $settings['tokenUrl'],
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER => array('Accept: application/json'),
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => http_build_query(
						array(
							'code' => $authorizationCode,
							'grant_type' => 'authorization_code',
							'client_id' => $settings['clientId'],
							'client_secret' => $settings['clientSecret'],
							'redirect_uri' => Application::get()->getRequest()->url(
								null,
								'openid',
								'doAuthentication',
								null,
								array('provider' => $selectedProvider)
							),
						)
					),
				)
			);
			$result = curl_exec($curl);
			curl_close($curl);
			if (isset($result) && !empty($result)) {
				$result = json_decode($result, true);
				if (is_array($result) && !empty($result) && key_exists('access_token', $result)) {
					$token = [
						'access_token' => $result['access_token'],
						'id_token' => key_exists('id_token', $result) ? $result['id_token'] : null,
						'refresh_token' => key_exists('refresh_token', $result) ? $result['refresh_token'] : null,
					];
				}
			}
		}

		return $token;
	}

	/**
	 * This function uses the certs endpoint of the openid provider to get the server certificate.
	 * If no key is found, null is returned
	 *
	 * @param array $providerList
	 * @param string $selectedProvider
	 * @return array
	 */
	private function _getOpenIDAuthenticationCert(array $providerList, string $selectedProvider)
	{
		$publicKeys = null;
		if (isset($providerList) && key_exists($selectedProvider, $providerList)) {
			$settings = $providerList[$selectedProvider];
			$beginCert = '-----BEGIN CERTIFICATE-----';
			$endCert = '-----END CERTIFICATE----- ';
			$curl = curl_init();
			curl_setopt_array(
				$curl,
				array(
					CURLOPT_URL => $settings['certUrl'],
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER => array('Accept: application/json'),
					CURLOPT_POST => false,
				)
			);
			$result = curl_exec($curl);
			curl_close($curl);
			$arr = json_decode($result, true);
			if (key_exists('keys', $arr)) {
				$publicKeys = array();
				foreach ($arr['keys'] as $key) {
					if (key_exists('alg', $key) && $key['alg'] = 'RS256') {
						if (key_exists('x5c', $key) && $key['x5c'] != null && is_array($key['x5c'])) {
							foreach ($key['x5c'] as $n) {
								if (!empty($n)) {
									$publicKeys[] = $beginCert.PHP_EOL.$n.PHP_EOL.$endCert;
								}
							}
						} elseif (key_exists('n', $key) && key_exists('e', $key)) {
							$rsa = new RSA();
							$modulus = new BigInteger(JWT::urlsafeB64Decode($key['n']), 256);
							$exponent = new BigInteger(JWT::urlsafeB64Decode($key['e']), 256);
							$rsa->loadKey(array('n' => $modulus, 'e' => $exponent));
							$publicKeys[] = $rsa->getPublicKey();
						}
					}
				}
			}
		}

		return $publicKeys;
	}

	/**
	 * Validates the token via JWT and public key and returns the token payload data as array.
	 * In case of an error null is returned
	 *
	 * @param array $token
	 * @param array $publicKeys
	 * @return array|null
	 */
	private function _validateAndExtractToken(array $token, array $publicKeys)
	{
		$credentials = null;
		foreach ($publicKeys as $publicKey) {
			foreach ($token as $t) {
				try {
					if (!empty($t)) {
						$jwtPayload = JWT::decode($t, $publicKey, array('RS256'));
						if (isset($jwtPayload)) {
							$credentials = [
								'id' => property_exists($jwtPayload, 'sub') ? $jwtPayload->sub : null,
								'email' => property_exists($jwtPayload, 'email') ? $jwtPayload->email : null,
								'username' => property_exists($jwtPayload, 'preferred_username') ? $jwtPayload->preferred_username : null,
								'given_name' => property_exists($jwtPayload, 'given_name') ? $jwtPayload->given_name : null,
								'family_name' => property_exists($jwtPayload, 'family_name') ? $jwtPayload->family_name : null,
								'email_verified' => property_exists($jwtPayload, 'email_verified') ? $jwtPayload->email_verified : null,
							];
						}
						if (isset($credentials) && key_exists('id', $credentials) && !empty($credentials['id'])) {
							break 2;
						}
					}
				} catch (Exception $e) {
					$credentials = null;
				}
			}
		}

		return $credentials;
	}


	private function getClientDetails($token, $settings)
	{
		$curl = curl_init();
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_URL => $settings['userInfoUrl'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => array('Accept: application/json', 'Authorization: Bearer '.$token['access_token']),
				CURLOPT_POST => false,
			)
		);
		$result = curl_exec($curl);
		curl_close($curl);
	}


}

