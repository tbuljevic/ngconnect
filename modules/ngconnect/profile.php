<?php

$module = $Params['Module'];
$http = eZHTTPTool::instance();
$siteINI = eZINI::instance();
$ngConnectINI = eZINI::instance('ngconnect.ini');
$regularRegistration = (trim($ngConnectINI->variable('ngconnect', 'RegularRegistration')) == 'enabled');

if($http->hasSessionVariable('NGConnectAuthResult') && $regularRegistration)
{
	$authResult = $http->sessionVariable('NGConnectAuthResult');

	if($http->hasPostVariable('SkipButton'))
	{
		// user wants to skip connecting accounts
		// again, who are we to say no? so just create the user and bail out

		$user = ngConnectFunctions::createUser($authResult);
		if($user instanceof eZUser && $user->canLoginToSiteAccess($GLOBALS['eZCurrentAccess']))
		{
			$user->loginCurrent();
		}
		else
		{
			eZUser::logoutCurrent();
		}

		redirect($http, $module);
	}
	else if($http->hasPostVariable('LoginButton'))
	{
		// user is trying to connect to the existing account

		$login = trim($http->postVariable('Login'));
		$password = trim($http->postVariable('Password'));

		$userToLogin = eZUser::fetchByName($login);
		if($userToLogin instanceof eZUser && $userToLogin->PasswordHash == eZUser::createHash($login, $password, eZUser::site(), eZUser::hashType()))
		{
			if($userToLogin->isEnabled() && $userToLogin->canLoginToSiteAccess($GLOBALS['eZCurrentAccess']))
			{
				$userToLogin->loginCurrent();
				ngConnectFunctions::connectUser($userToLogin->ContentObjectID, $authResult['login_method'], $authResult['id']);
				redirect($http, $module);
			}
			else
			{
				$badLogin = false;
				$loginNotAllowed = true;
			}
		}
		else
		{
			$badLogin = true;
			$loginNotAllowed = false;
		}
	}
	else if($http->hasPostVariable('SaveButton'))
	{
		// user wants to connect by creating a new eZ Publish account

		if($http->hasSessionVariable('NGConnectStartedRegistration'))
		{
			eZDebug::writeWarning('Cancel module run to protect against multiple form submits', 'ngconnect/profile');
			$http->removeSessionVariable('NGConnectStartedRegistration');
			return eZModule::HOOK_STATUS_CANCEL_RUN;
		}
		$http->setSessionVariable('NGConnectStartedRegistration', 1);

		$validationResult = ngConnectUserActivation::validateUserInput($http);

		if($validationResult['status'] == 'success')
		{
			$login = trim($http->postVariable('data_user_login'));
			$email = trim($http->postVariable('data_user_email'));
			$password = trim($http->postVariable('data_user_password'));

			if(strlen($password) == 0 && $siteINI->variable('UserSettings', 'GeneratePasswordIfEmpty') == 'true')
			{
				$password = $user->createPassword($siteINI->variable('UserSettings', 'GeneratePasswordLength'));
			}

			$user = ngConnectFunctions::createUser($authResult);
			if($user instanceof eZUser)
			{
				// we created the new account, but still need to set things up so users can login using a regular login form

				$db = eZDB::instance();
				$db->begin();

				$user->setAttribute('login', $login);
				$user->setAttribute('email', $email);
				$user->setAttribute('password_hash', eZUser::createHash($login, $password, eZUser::site(), eZUser::hashType()));
				$user->setAttribute('password_hash_type', eZUser::hashType());
				$user->store();

				ngConnectFunctions::connectUser($user->ContentObjectID, $authResult['login_method'], $authResult['id']);

				$db->commit();

				$http->removeSessionVariable('NGConnectStartedRegistration');

				if($authResult['email'] == '' || $email != $authResult['email'])
				{
					// we only validate the account if no email was provided by social network or entered email is not the same
					// as the one from social network

					ngConnectUserActivation::processUserActivation($user, $password);
					$http->removeSessionVariable('NGConnectAuthResult');
					return $module->redirectToView('success');
				}
				else
				{
					if($user->canLoginToSiteAccess($GLOBALS['eZCurrentAccess']))
					{
						$user->loginCurrent();
					}
					else
					{
						eZUser::logoutCurrent();
					}
				}

				redirect($http, $module);
			}
			else
			{
				eZUser::logoutCurrent();
			}
		}
		else
		{
			$validationError = $validationResult['message'];
		}

		$http->removeSessionVariable('NGConnectStartedRegistration');
	}

	$tpl = eZTemplate::factory();
	$tpl->setVariable('network_email', trim($authResult['email']));

	if(isset($badLogin) && $badLogin)
		$tpl->setVariable('bad_login', true);
	else if(isset($loginNotAllowed) && $loginNotAllowed)
		$tpl->setVariable('login_not_allowed', true);
	else if(isset($validationError))
		$tpl->setVariable('validation_error', $validationError);

	$tpl->setVariable('persistent_variable', false);

	$Result = array();
	$Result['content'] = $tpl->fetch( 'design:ngconnect/profile.tpl' );
	$Result['path'] = array(array(	'text' => ezpI18n::tr('extension/ngconnect/ngconnect/profile', 'Profile setup'),
									'url' => false));

	$contentInfoArray = array();
	$contentInfoArray['persistent_variable'] = false;
	if($tpl->variable('persistent_variable') !== false)
		$contentInfoArray['persistent_variable'] = $tpl->variable('persistent_variable');
	$Result['content_info'] = $contentInfoArray;
}
else
{
	redirect($http, $module);
}

function redirect($http, $module)
{
	$http->removeSessionVariable('NGConnectAuthResult');
	if($http->hasSessionVariable('NGConnectLastAccessURI'))
	{
		return $module->redirectTo($http->sessionVariable('NGConnectLastAccessURI'));
	}
	else
	{
		return $module->redirectTo('/');
	}
}

?>
