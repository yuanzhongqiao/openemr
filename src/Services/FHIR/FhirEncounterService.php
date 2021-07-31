<?php

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\Export\ExportCannotEncodeException;
use OpenEMR\FHIR\Export\ExportException;
use OpenEMR\FHIR\Export\ExportJob;
use OpenEMR\FHIR\Export\ExportStreamWriter;
use OpenEMR\FHIR\Export\ExportWillShutdownException;
use OpenEMR\FHIR\R4\FHIRElement\FHIRIdentifier;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIREncounter\FHIREncounterHospitalization;
use OpenEMR\FHIR\R4\FHIRResource\FHIREncounter\FHIREncounterLocation;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIREncounter;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCode;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRPeriod;
use OpenEMR\FHIR\R4\FHIRElement\FHIRReference;
use OpenEMR\FHIR\R4\FHIRResource\FHIREncounter\FHIREncounterParticipant;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

class FhirEncounterService extends FhirServiceBase implements IFhirExportableResourceService, IPatientCompartmentResourceService
{
    use PatientSearchTrait;
    use FhirServiceBaseEmptyTrait;

    const ENCOUNTER_STATUS_FINISHED = "finished";

    const ENCOUNTER_TYPE_CHECK_UP = "185349003";
    const ENCOUNTER_TYPE_CHECK_UP_DESCRIPTION = "Encounter for check up (procedure)";

    const ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER = "PPRF";
    const ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER_TEXT = "Primary Performer";


    /**
     * @var EncounterService
     */
    private $encounterService;

    public function __construct()
    {
        parent::__construct();
        $this->encounterService = new EncounterService();
    }

    /**
     * Returns an array mapping FHIR Encounter Resource search parameters to OpenEMR Encounter search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('euuid', ServiceField::TYPE_UUID)]),
            'patient' => $this->getPatientContextSearchField(),
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['date'])
        ];
    }

    /**
     * Parses an OpenEMR patient record, returning the equivalent FHIR Patient Resource
     * https://build.fhir.org/ig/HL7/US-Core-R4/StructureDefinition-us-core-encounter-definitions.html
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIREncounter
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $encounterResource = new FHIREncounter();

        $meta = new FHIRMeta();
        $meta->setVersionId('1');
        $meta->setLastUpdated(gmdate('c'));
        $encounterResource->setMeta($meta);

        $id = new FhirId();
        $id->setValue($dataRecord['euuid']);
        $encounterResource->setId($id);

        // identifier - required
        $identifier = new FHIRIdentifier();
        $identifier->setValue($dataRecord['euuid']);
        $identifier->setSystem(FhirCodeSystemConstants::RFC_3986);
        $encounterResource->addIdentifier($identifier);

        // status - required
        $status = new FHIRCode(self::ENCOUNTER_STATUS_FINISHED);
        $encounterResource->setStatus($status);

        // class
        if (!empty($dataRecord['class_code'])) {
            $class = new FHIRCoding();
            $class->setSystem(FhirCodeSystemConstants::HL7_V3_ACT_CODE);
            $class->setCode($dataRecord['class_code']);
            $class->setDisplay($dataRecord['class_title']);
            $encounterResource->setClass($class);
        } else {
            $encounterResource->setClass(UtilsService::createDataAbsentUnknownCodeableConcept());
        }

        // TODO: @adunsulag check with @brady.miller and find out if this really is the only possible encounter type...  it was here originally
        $type = UtilsService::createCodeableConcept(
            [self::ENCOUNTER_TYPE_CHECK_UP => self::ENCOUNTER_TYPE_CHECK_UP_DESCRIPTION],
            FhirCodeSystemConstants::SNOMED_CT
        );
        $encounterResource->addType($type);

        // subject - required
        if (!empty($dataRecord['puuid'])) {
            $encounterResource->setSubject(UtilsService::createRelativeReference('Patient', $dataRecord['puuid']));
        } else {
            $encounterResource->setSubject(UtilsService::createDataMissingExtension());
        }

        // participant - must support
        if (!empty($dataRecord['provider_uuid'])) {
            $participant = new FHIREncounterParticipant();
            $participant->setIndividual(UtilsService::createRelativeReference("Practitioner", $dataRecord['provider_uuid']));
            $period = new FHIRPeriod();
            $period->setStart(gmdate('c', strtotime($dataRecord['date'])));
            $participant->setPeriod($period);

            $participantType = UtilsService::createCodeableConcept([self::ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER =>
                self::ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER_TEXT], FhirCodeSystemConstants::HL7_PARTICIPATION_TYPE);
            $participant->addType($participantType);
            $encounterResource->addParticipant($participant);
        }

        // period - must support
        if (!empty($dataRecord['date'])) {
            $period = new FHIRPeriod();
            $period->setStart(gmdate('c', strtotime($dataRecord['date'])));
            $encounterResource->setPeriod($period);
        }

        // reasonCode - must support OR must support reasonReference
        if (!empty($dataRecord['reason'])) {
            // Note: that we use the encounter textual representation for the reason here which is just fine as ccda
            // uses a textual representation of this.  According to HL7 chat this is just fine as epoch and other systems
            // do it this way
            // @see https://chat.fhir.org/#narrow/stream/179175-argonaut/topic/Encounter.20Reason.20For.20Visit (beware of link rot)
            $reason = new FHIRCodeableConcept();
            $reason->setText($dataRecord['reason']);
            $encounterResource->addReasonCode($reason);
        }
        // hospitalization - must support

        // hospitalization.dischargeDisposition - must support
        if (!empty($dataRecord['discharge_disposition'])) {
            $code = $dataRecord['discharge_disposition'];
            $text = $dataRecord['discharge_disposition_text'];

            $hospitalization = new FHIREncounterHospitalization();
            $hospitalization->setDischargeDisposition(UtilsService::createCodeableConcept(
                [$code => $text],
                FhirCodeSystemConstants::HL7_DISCHARGE_DISPOSITION
            ));
            $encounterResource->setHospitalization($hospitalization);
        }

        // SHALL support either location.location OR serviceProvider
        // however ONC inferno requires both serviceProvider AND location.location
        // location.location - must support
        // serviceProvider - must support
        if (!empty($dataRecord['facility_uuid'])) {
            $encounterResource->setServiceProvider(UtilsService::createRelativeReference('Organization', $dataRecord['facility_uuid']));

            // grab the facility location address
            if (!empty($dataRecord['facility_location_uuid'])) {
                $location = new FHIREncounterLocation();
                $location->setLocation(UtilsService::createRelativeReference("Location", $dataRecord['facility_location_uuid']));
                $encounterResource->addLocation($location);
            }
        }

        if ($encode) {
            return json_encode($encounterResource);
        } else {
            return $encounterResource;
        }
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param array openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult
     */
    protected function searchForOpenEMRRecords($searchParam, $puuidBind = null): ProcessingResult
    {
        return $this->encounterService->search($searchParam, true, $puuidBind);
    }

    /**
     * Grabs all the objects in my service that match the criteria specified in the ExportJob.  If a
     * $lastResourceIdExported is provided, The service executes the same data collection query it used previously and
     * startes processing at the resource that is immediately after (ordered by date) the resource that matches the id of
     * $lastResourceIdExported.  This allows processing of the service to be resumed or paused.
     * @param ExportStreamWriter $writer Object that writes out to a stream any object that extend the FhirResource object
     * @param ExportJob $job The export job we are processing the request for.  Holds all of the context information needed for the export service.
     * @return void
     * @throws ExportWillShutdownException  Thrown if the export is about to be shutdown and all processing must be halted.
     * @throws ExportException  If there is an error in processing the export
     * @throws ExportCannotEncodeException Thrown if the resource cannot be properly converted into the right format (ie JSON).
     */
    public function export(ExportStreamWriter $writer, ExportJob $job, $lastResourceIdExported = null): void
    {
        // TODO: encounter search should support multiple date parameter searches for interval period search
//        $date = [
//            'le' . $job->getStartTime()->format(\DateTime::RFC3339_EXTENDED)
//            ,'ge' . $job->getResourceIncludeTime()->format(\DateTime::RFC3339_EXTENDED)
//        ];
        $date = 'le' . $job->getStartTime()->format(\DateTime::RFC3339_EXTENDED);
        $result = $this->getAll(['date' => $date]);
        $encounters = $result->getData();
        if (!empty($encounters)) {
            foreach ($encounters as $encounter) {
                $writer->append($encounter);
            }
        }
    }

    /**
     * Returns whether the service supports the system export operation
     * @see https://hl7.org/fhir/uv/bulkdata/export/index.html#endpoint---system-level-export
     * @return bool true if this resource service should be called for a system export operation, false otherwise
     */
    public function supportsSystemExport()
    {
        return true;
    }

    /**
     * Returns whether the service supports the group export operation.
     * Note only resources in the Patient compartment SHOULD be returned unless the resource assists in interpreting
     * patient data (such as Organization or Practitioner)
     * @see https://hl7.org/fhir/uv/bulkdata/export/index.html#endpoint---group-of-patients
     * @return bool true if this resource service should be called for a group export operation, false otherwise
     */
    public function supportsGroupExport()
    {
        return true;
    }

    /**
     * Returns whether the service supports the all patient export operation
     * Note only resources in the Patient compartment SHOULD be returned unless the resource assists in interpreting
     * patient data (such as Organization or Practitioner)
     * @see https://hl7.org/fhir/uv/bulkdata/export/index.html#endpoint---all-patients
     * @return bool true if this resource service should be called for a patient export operation, false otherwise
     */
    public function supportsPatientExport()
    {
        return true;
    }
}
