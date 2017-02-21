<? namespace Intervolga\Migrato;

use Intervolga\Migrato\Data\BaseData;
use Intervolga\Migrato\Tool\Config;
use Intervolga\Migrato\Data\Record;
use Intervolga\Migrato\Tool\XmlIdValidateError;

class Migrato
{
	/**
	 * @var string[]
	 */
	protected static $reports = array();

	public static function run()
	{
		static::$reports = array();
	}

	/**
	 * @return string[]
	 */
	public static function getReports()
	{
		return static::$reports;
	}

	/**
	 * @return Tool\XmlIdValidateError[]
	 */
	public static function validate()
	{
		$result = array();
		$configDataClasses = Config::getInstance()->getDataClasses();
		$dataClasses = static::recursiveGetDependentDataClasses($configDataClasses);
		foreach ($dataClasses as $data)
		{
			$filter = Config::getInstance()->getDataClassFilter($data);
			if (!$data->getXmlIdProvider()->isXmlIdFieldExists())
			{
				$data->getXmlIdProvider()->createXmlIdField();
			}
			$result = static::validateData($data, $filter);
		}

		return $result;
	}

	/**
	 * @param BaseData[] $dataClasses
	 *
	 * @return BaseData[]
	 */
	protected static function recursiveGetDependentDataClasses(array $dataClasses)
	{
		$newClassesAdded = false;
		foreach ($dataClasses as $dataClass)
		{
			$dependencies = $dataClass->getDependencies();
			if ($dependencies)
			{
				foreach ($dependencies as $dependency)
				{
					$dependentDataClass = $dependency->getTargetData();
					if (!in_array($dependentDataClass, $dataClasses))
					{
						$dataClasses[] = $dependentDataClass;
						$newClassesAdded = true;
					}
				}
			}
			$references = $dataClass->getReferences();
			if ($references)
			{
				foreach ($references as $reference)
				{
					$dependentDataClass = $reference->getTargetData();
					if (!in_array($dependentDataClass, $dataClasses))
					{
						$dataClasses[] = $dependentDataClass;
						$newClassesAdded = true;
					}
				}
			}
		}
		if ($newClassesAdded)
		{
			return static::recursiveGetDependentDataClasses($dataClasses);
		}
		else
		{
			return $dataClasses;
		}
	}

	/**
	 * @param \Intervolga\Migrato\Data\BaseData $dataClass
	 * @param string[] $filter
	 *
	 * @return \Intervolga\Migrato\Tool\XmlIdValidateError[]
	 */
	protected static function validateData(BaseData $dataClass, array $filter = array())
	{
		$errors = array();
		$records = $dataClass->getList($filter);
		$xmlIds[] = array();
		foreach ($records as $record)
		{
			$errorType = 0;
			if ($record->getXmlId())
			{
				$matches = array();
				if (preg_match_all("/^[a-z0-9\-_]*$/i", $record->getXmlId(), $matches))
				{
					if (!in_array($record->getXmlId(), $xmlIds))
					{
						$xmlIds[] = $record->getXmlId();
					}
					else
					{
						$errorType = XmlIdValidateError::TYPE_REPEAT;
					}
				}
				else
				{
					$errorType = XmlIdValidateError::TYPE_INVALID;
				}
			}
			else
			{
				$errorType = XmlIdValidateError::TYPE_EMPTY;

			}
			if ($errorType)
			{
				$errors[] = new XmlIdValidateError($dataClass, $errorType, $record->getId(), $record->getXmlId());
			}
		}

		return $errors;
	}

	/**
	 * @param XmlIdValidateError[] $errors
	 */
	protected static function fixErrors(array $errors)
	{
		foreach ($errors as $error)
		{
			if ($error->getType() == XmlIdValidateError::TYPE_EMPTY)
			{
				$error->getDataClass()->getXmlIdProvider()->generateXmlId($error->getId());
			}
			elseif ($error->getType() == XmlIdValidateError::TYPE_INVALID)
			{
				$xmlId = $error->getDataClass()->getXmlIdProvider()->getXmlId($error->getId());
				$xmlId = preg_replace("/[^a-z0-9\-_]/", "-", $xmlId);
				$error->getDataClass()->getXmlIdProvider()->setXmlId($error->getId(), $xmlId);
			}
			elseif ($error->getType() == XmlIdValidateError::TYPE_REPEAT)
			{
				$error->getDataClass()->getXmlIdProvider()->generateXmlId($error->getId());
			}
		}
	}

	/**
	 * @param string $module
	 *
	 * @return string
	 */
	protected static function getModuleOptionsDirectory($module)
	{
		return INTERVOLGA_MIGRATO_DIRECTORY . $module . "/";
	}

	/**
	 * @param \Intervolga\Migrato\Data\Record $dataRecord
	 * @param \Exception $exception
	 * @param string $message
	 */
	protected static function reportRecordException(Record $dataRecord, \Exception $exception, $message)
	{
		$report = static::getRecordNameForReport($dataRecord) . " " . $message;
		if ($exception->getMessage())
		{
			$report .= " exception: " . $exception->getMessage();
		}
		static::report($report, "fail");
	}

	/**
	 * @param \Intervolga\Migrato\Data\Record $dataRecord
	 *
	 * @return string
	 */
	protected static function getRecordNameForReport(Record $dataRecord)
	{
		$data = $dataRecord->getData();
		$recordName = "Record ";
		$recordName .= $data->getModule() . "/" . $data->getEntityName();
		if ($dataRecord->getXmlId())
		{
			$recordName .= " (xmlid: " . $dataRecord->getXmlId() . ")";
		}
		elseif ($id = $dataRecord->getId())
		{
			$idValue = $id->getValue();
			if (is_array($idValue))
			{
				$recordName .= " (id: [" . implode(",", $idValue) . "])";
			}
			else
			{
				$recordName .= " (id: " . $idValue . ")";
			}
		}

		return $recordName;
	}

	/**
	 * @param \Intervolga\Migrato\Data\Record $dataRecord
	 * @param string $message
	 */
	protected static function reportRecord(Record $dataRecord, $message)
	{
		static::report(static::getRecordNameForReport($dataRecord) . " " . $message, "ok");
	}

	/**
	 * @param \Intervolga\Migrato\Data\BaseData $data
	 * @param string $message
	 */
	protected static function reportData(BaseData $data, $message)
	{
		static::report(static::getDataNameForReport($data) . " " . $message, "ok");
	}

	/**
	 * @param \Intervolga\Migrato\Data\BaseData $data
	 *
	 * @return string
	 */
	protected static function getDataNameForReport(BaseData $data)
	{
		$recordName = "Data ";
		$recordName .= $data->getModule() . "/" . $data->getEntityName();

		return $recordName;
	}

	/**
	 * @param \Intervolga\Migrato\Data\BaseData $data
	 * @param \Exception $exception
	 * @param string $message
	 */
	protected static function reportDataException(BaseData $data, \Exception $exception, $message)
	{
		$report = static::getDataNameForReport($data) . " " . $message;
		if ($exception->getMessage())
		{
			$report .= " exception: " . $exception->getMessage();
		}
		static::report($report, "fail");
	}

	/**
	 * @param string $message
	 * @param string $type
	 */
	protected static function report($message, $type = "")
	{
		list($microSec,) = explode(" ", microtime());
		$microSec = round($microSec, 3)*1000;
		$microSec = str_pad($microSec, 3, "0", STR_PAD_RIGHT);
		$type = trim($type);
		if ($type)
		{
			$type = "[" . $type . "] ";
		}
		static::$reports[] = date("d.m.Y H:i:s") . ":" . $microSec . " " . $type . $message;
	}
}