<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\OpenPgpWebclient;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractWebclientModule
{
	public function init() 
	{
		\Aurora\Modules\Core\Classes\User::extend(
			self::GetName(),
			[
				'EnableModule'	=> ['bool', false],
			]
		);

		\Aurora\Modules\Contacts\Classes\Contact::extend(
			self::GetName(),
			[
				'PgpKey'	=> ['text', null],
			]

		);
		
		$this->subscribeEvent('Files::PopulateFileItem::after', array($this, 'onAfterPopulateFileItem'));
		$this->subscribeEvent('Mail::GetBodyStructureParts', array($this, 'onGetBodyStructureParts'));
		$this->subscribeEvent('Mail::ExtendMessageData', array($this, 'onExtendMessageData'));
		$this->subscribeEvent('Contacts::CreateContact::after', array($this, 'onAfterCreateOrUpdateContact'));
		$this->subscribeEvent('Contacts::UpdateContact::after', array($this, 'onAfterCreateOrUpdateContact'));		
		$this->subscribeEvent('Contacts::GetContacts::after', array($this, 'onAfterGetContacts'));		

		$this->subscribeEvent('Contacts::Contact::ToResponseArray', array($this, 'onContactsContactToResponseArray'));		
	}
	
	/**
	 * @ignore
	 * @todo not used
	 * @param array $aArgs
	 * @param object $oItem
	 * @return boolean
	 */
	public function onAfterPopulateFileItem($aArgs, &$oItem)
	{
		if ($oItem && '.asc' === \strtolower(\substr(\trim($oItem->Name), -4)))
		{
			$oFilesDecorator = \Aurora\System\Api::GetModuleDecorator('Files');
			if ($oFilesDecorator instanceof \Aurora\System\Module\Decorator)
			{
				$mResult = $oFilesDecorator->GetFileContent($aArgs['UserId'], $oItem->TypeStr, $oItem->Path, $oItem->Name);
				if (isset($mResult))
				{
					$oItem->Content = $mResult;
				}
			}
		}
	}	
	
	public function onGetBodyStructureParts($aParts, &$aResultParts)
	{
		foreach ($aParts as $oPart)
		{
			if ($oPart instanceof \MailSo\Imap\BodyStructure && $oPart->ContentType() === 'text/plain' && '.asc' === \strtolower(\substr(\trim($oPart->FileName()), -4)))
			{
				$aResultParts[] = $oPart;
			}
		}
	}
	
	public function onExtendMessageData($aData, &$oMessage)
	{
		foreach ($aData as $aDataItem)
		{
			$oPart = $aDataItem['Part'];
			$bAsc = $oPart instanceof \MailSo\Imap\BodyStructure && $oPart->ContentType() === 'text/plain' && '.asc' === \strtolower(\substr(\trim($oPart->FileName()), -4));
			$sData = $aDataItem['Data'];
			if ($bAsc)
			{
				$iMimeIndex = $oPart->PartID();
				foreach ($oMessage->getAttachments()->GetAsArray() as $oAttachment)
				{
					if ($iMimeIndex === $oAttachment->getMimeIndex())
					{
						$oAttachment->setContent($sData);
					}
				}
			}
		}
	}

	public function onAfterCreateOrUpdateContact($aArgs, &$mResult)
	{
		if (isset($mResult['UUID']) && isset($aArgs['Contact']['PublicPgpKey']))
		{
			$sPublicPgpKey = $aArgs['Contact']['PublicPgpKey'];
			if (empty(\trim($sPublicPgpKey)))
			{
				$sPublicPgpKey = null;
			}
			$oContact = \Aurora\Modules\Contacts\Module::Decorator()->GetContact($mResult['UUID'], $aArgs['UserId']);
			if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && $oContact->{$this->GetName() . '::PgpKey'} !== $sPublicPgpKey)
			{
				$oContact->{$this->GetName() . '::PgpKey'} = $sPublicPgpKey;
				\Aurora\Modules\Contacts\Module::Decorator()->UpdateContactObject($oContact);
			}			
		}		
	}	

	public function onContactsContactToResponseArray($aArgs, &$mResult)
	{
		if (isset($mResult[$this->GetName() . '::PgpKey']))
		{
			$mResult['PublicPgpKey'] = $mResult[$this->GetName() . '::PgpKey'];
			unset($mResult[$this->GetName() . '::PgpKey']);
		}
	}
	
	public function onAfterGetContacts($aArgs, &$mResult)
	{
		if (isset($mResult['List']))
		{
			$aContactUUIDs = array_map(function ($aValue) {
				return $aValue['UUID'];
			}, $mResult['List']);
			$aContactsInfo = $this->GetContactsWithPublicKeys($aArgs['UserId'], $aContactUUIDs);
			foreach ($mResult['List'] as &$aContact)
			{
				$aContact['HasPgpPublicKey'] = $aContactsInfo[$aContact['UUID']];
			}
		}	
	}
	
	/***** public functions might be called with web API *****/
	/**
	 * Obtains list of module settings for authenticated user.
	 * 
	 * @return array
	 */
	public function GetSettings()
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		$aSettings = array();
		$oUser = \Aurora\System\Api::getAuthenticatedUser();
		if ($oUser && $oUser->isNormalOrTenant())
		{
			if (isset($oUser->{self::GetName().'::EnableModule'}))
			{
				$aSettings['EnableModule'] = $oUser->{self::GetName().'::EnableModule'};
			}
		}
		return $aSettings;
	}
	
	public function UpdateSettings($EnableModule)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		$oUser = \Aurora\System\Api::getAuthenticatedUser();
		if ($oUser)
		{
			if ($oUser->isNormalOrTenant())
			{
				$oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
				$oUser->{self::GetName().'::EnableModule'} = $EnableModule;
				return $oCoreDecorator->UpdateUserObject($oUser);
			}
			if ($oUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin)
			{
				return true;
			}
		}
		
		return false;
	}

	public function AddPublicKeyToContact($UserId, $Email, $Key)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

		$bResult = false;

		if (\MailSo\Base\Validator::SimpleEmailString($Email))
		{
			$aContacts = \Aurora\Modules\Contacts\Module::Decorator()->GetContactsByEmails(
				$UserId, 
				\Aurora\Modules\Contacts\Enums\StorageType::Personal, 
				[$Email]
			);

			if (count($aContacts) === 0)
			{
				$mResult = \Aurora\Modules\Contacts\Module::Decorator()->CreateContact(
					['PersonalEmail' => $Email],
					$UserId
				);	
				if (isset($mResult['UUID']))
				{
					$oContact = \Aurora\Modules\Contacts\Module::Decorator()->GetContact($mResult['UUID'], $UserId);
					if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && 
						$oContact->Storage === \Aurora\Modules\Contacts\Enums\StorageType::Personal)
					{
						$aContacts = [$oContact];
					}
				}
			}

			if (is_array($aContacts) && count($aContacts) > 0)
			{
				foreach ($aContacts as $oContact)
				{
					if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && 
						$oContact->Storage === \Aurora\Modules\Contacts\Enums\StorageType::Personal)
					{
						$oContact->{$this->GetName() . '::PgpKey'} = $Key;
						\Aurora\Modules\Contacts\Module::Decorator()->UpdateContactObject($oContact);
					}
				}

				$bResult = true;
			}
		}

		return $bResult;
	}

	public function RemovePublicKeyFromContact($UserId, $Email)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

		$bResult = false;

		if (\MailSo\Base\Validator::SimpleEmailString($Email))
		{
			$aContacts = \Aurora\Modules\Contacts\Module::Decorator()->GetContactsByEmails(
				$UserId, 
				\Aurora\Modules\Contacts\Enums\StorageType::Personal, 
				[$Email]
			);

			if (is_array($aContacts) && count($aContacts) > 0)
			{
				foreach ($aContacts as $oContact)
				{
					if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && 
						$oContact->Storage === \Aurora\Modules\Contacts\Enums\StorageType::Personal)
					{
						$oContact->{$this->GetName() . '::PgpKey'} = null;
						\Aurora\Modules\Contacts\Module::Decorator()->UpdateContactObject($oContact);
					}
				}

				$bResult = true;
			}		
		}

		return $bResult;
	}

	public function GetPublicKeysByCountactUUIDs($UserId, $ContactUUIDs)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

		$aResult = [];

		if (count($ContactUUIDs))
		{
			$aContacts = \Aurora\Modules\Contacts\Module::Decorator()->GetContactsByUids($UserId, $ContactUUIDs);
			if (is_array($aContacts) && count($aContacts) > 0)
			{
				foreach ($aContacts as $oContact)
				{
					$aResult[] = [
						'Email' => $oContact->ViewEmail,
						'PublicPgpKey' => $oContact->{$this->GetName() . '::PgpKey'}
					];
				}
			}	
		}

		return $aResult;
	}

	public function GetContactsWithPublicKeys($UserId, $UUIDs)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		$aResult = [];

		$aContactsInfo = \Aurora\Modules\Contacts\Module::Decorator()->GetContactsInfo(
			\Aurora\Modules\Contacts\Enums\StorageType::Personal,
			$UserId,
			[
				'$AND' => [
					$this->GetName() . '::PgpKey' => ['NULL', 'IS NOT'],
					'UUID' => [$UUIDs, 'IN']
				]
			]
		);	
		$aContactUUIDs = [];
		if (isset($aContactsInfo['Info']) && count($aContactsInfo['Info']) > 0)
		{
			$aContactUUIDs = array_map(function ($aValue) {
				return $aValue['UUID'];
			}, $aContactsInfo['Info']);
		}
		foreach ($UUIDs as $sUUID)
		{
			$mResult[$sUUID] = in_array($sUUID, $aContactUUIDs) ? true : false;
		}

		return $mResult;
	}

	public function GetPublicKeysFromContacts($UserId)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

		$aResult = [];

		$aContactsInfo = \Aurora\Modules\Contacts\Module::Decorator()->GetContactsInfo(
			\Aurora\Modules\Contacts\Enums\StorageType::Personal,
			$UserId,
			[
				'$AND' => [
					$this->GetName() . '::PgpKey' => ['NULL', 'IS NOT']
				]
			]
		);

		$aContactUUIDs = [];
		if (is_array($aContactsInfo['Info']) && count($aContactsInfo['Info']) > 0)
		{
			$aContactUUIDs = array_map(function ($aValue) {
				return $aValue['UUID'];
			}, $aContactsInfo['Info']);
		}
		$aResult = $this->Decorator()->GetPublicKeysByCountactUUIDs($UserId, $aContactUUIDs);


		return $aResult;
	}
	/***** public functions might be called with web API *****/
}
