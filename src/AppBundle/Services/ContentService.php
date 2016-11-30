<?php
/**
 * File containing the ContentService class.
 *
 * (c) www.aplyca.com
 * (c) Developer jdiaz@aplyca.com
 */

namespace CCB\BFWBundle\Services;

use Symfony\Component\DependencyInjection\Container;
use eZ\Publish\API\Repository\Repository;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\Core\Helper\FieldHelper;
use Psr\Log\LoggerInterface;
use eZ\Publish\Core\MVC\ConfigResolverInterface;

/**
 * Helper class for getting ccb content easily.
 */
class ContentService
{
    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    private $container;

    /**
     * @var \eZ\Publish\API\Repository\Repository
     */
    private $repository;

    /**
     * @var \eZ\Publish\Core\Helper\FieldHelper
     */
    protected $fieldHelper;

    /**
     * @var LoggerInterface
     */
    protected $ccbLogger;

    /**
     * @var \eZ\Publish\Core\Helper\FieldHelper
     */
    private $configResolver;

    /**
     * @var LoggerInterface
     */
    protected $imageVariationHandler;

    public function __construct(Container $container, Repository $repository, FieldHelper $fieldHelper, LoggerInterface $ccbLogger = null, ConfigResolverInterface $configResolver, $imageVariationHandler)
    {
        $this->container = $container;
        $this->repository = $repository;
        $this->fieldHelper = $fieldHelper;
        $this->ccbLogger = $ccbLogger;
        $this->configResolver = $configResolver;
        $this->imageVariationHandler = $imageVariationHandler;
    }

    /**
     * Get content in the given params search
     * Returns an empty array if no found.
     *
     * @return values array
     */
    public function searchByParams($params)
    {
        $repository = $this->repository;
        $searchService = $repository->getSearchService();
        $languageService = $repository->getContentLanguageService();
        $languageCode = $languageService->getDefaultLanguageCode();
        $criterion = array();
        $sortClause = array();
        $sortClauseDirection = Query::SORT_ASC;

        $query = new Query();

        if (array_key_exists('subtree', $params)) {
            $criterion[] = new Criterion\Subtree($params['subtree']);
        }

        if (array_key_exists('locationId', $params)) {
            $criterion[] = new Criterion\ParentLocationId(array($params['locationId']));
        }

        if (array_key_exists('contentTypeIdentifier', $params)) {
            $criterion[] = new Criterion\ContentTypeIdentifier($params['contentTypeIdentifier']);
        }

        if (array_key_exists('field', $params)) {
            if (!empty($params['field'])) {
                foreach ($params['field'] as $fieldIdentifier => $fieldValue) {
                    $criterion[] = new Criterion\Field($fieldIdentifier, Criterion\Operator::EQ, $fieldValue);
                }
            }
        }

        if (array_key_exists('fieldRelation', $params)) {
            if (!empty($params['fieldRelation'])) {
                foreach ($params['fieldRelation'] as $fieldIdentifier => $fieldValue) {
                    $criterion[] = new Criterion\FieldRelation($fieldIdentifier, Criterion\Operator::IN, $fieldValue);
                }
            }
        }

        if (array_key_exists('depth', $params)) {
            $criterion[] = new Criterion\Depth(Operator::LTE, $params['depth']);
        }

        if (array_key_exists('filterClauseField', $params)) {
            if (array_key_exists('filterClauseFieldType', $params)) {
                if ($params['filterClauseFieldType'] == 'date') {
                    $criterion[] = new Criterion\Field($params['filterClauseField'], Criterion\Operator::GTE, time());
                } elseif ($params['filterClauseFieldType'] == 'value') {
                    $criterion[] = new Criterion\Field($params['filterClauseField'], Criterion\Operator::EQ, $params['filterClauseFieldValue']);
                }
            } else {
                $criterion[] = new Criterion\Field($params['filterClauseField'], Criterion\Operator::GTE, time());
            }
        }

        $criterion[] = new Criterion\Visibility(Criterion\Visibility::VISIBLE);

        $query->criterion = new Criterion\LogicalAnd(
            $criterion
        );

        if (array_key_exists('sortClause', $params)) {
            if (array_key_exists('sortClauseDirection', $params)) {
                if ($params['sortClauseDirection'] == 'asc') {
                    $sortClauseDirection = Query::SORT_ASC;
                } elseif ($params['sortClauseDirection'] == 'desc') {
                    $sortClauseDirection = Query::SORT_DESC;
                } else {
                    $sortClauseDirection = Query::SORT_ASC;
                }
            }

            if ($params['sortClause'] == 'priority') {
                $sortClause[] = new SortClause\LocationPriority($sortClauseDirection);
            } elseif ($params['sortClause'] == 'field') {
                if (array_key_exists('contentTypeIdentifier', $params) and array_key_exists('sortClauseFields', $params)) {
                    foreach ($params['contentTypeIdentifier'] as $contentTypeIdentifier) {
                        foreach ($params['sortClauseFields'] as $fieldIdentifier) {
                            $sortClause[] = new SortClause\Field($contentTypeIdentifier, $fieldIdentifier, $sortClauseDirection, $languageCode);
                        }
                    }
                }
            } else {
                $sortClause[] = new SortClause\DatePublished();
            }
        } else {
            $sortClause[] = new SortClause\DatePublished();
        }

        $query->sortClauses = $sortClause;

        if (array_key_exists('limit', $params)) {
            $query->limit = $params['limit'];
        }

        return $searchService->findContent($query);
    }

    /**
     * Get actors directory in the given Actors Content and json fields filter
     * Returns an empty array if no found.
     *
     * @return
     */
    public function getJsonFieldsValues($resultSearch, $jsonFields, $settings = array())
    {
        $jsonFieldsValues = array();

        try {
            $repository = $this->repository;
            $locationService = $repository->getLocationService();
            $contentService = $repository->getContentService();
            $contentTypeService = $repository->getContentTypeService();
            $html5Converter = $this->container->get('ezpublish.fieldType.ezxmltext.converter.html5');

            foreach ($resultSearch->searchHits as $index => $resultSearchItem) {
                $contentItem = $resultSearchItem->valueObject;
                $locationItem = $locationService->loadLocation($contentItem->contentInfo->mainLocationId);
                $contentTypeItem = $contentTypeService->loadContentType($contentItem->contentInfo->contentTypeId);
                $jsonFieldsValues[$index]['url'] = $this->container->get('router')->generate($locationItem);

                foreach ($contentTypeItem->fieldDefinitions as $fieldDefinition) {
                    if (in_array($fieldDefinition->identifier, $jsonFields)) {
                        if ($fieldDefinition->fieldTypeIdentifier == 'ezobjectrelationlist') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier)->destinationContentIds;

                            if (!empty($fieldValue)) {
                                foreach ($fieldValue as $key => $item) {
                                    $relationFieldContent = $contentService->loadContent($item);
                                    //$actorsDirectory[$index][$fieldDefinition->identifier][$relationFieldContent->id] = $relationFieldContent->contentInfo->name;
                                    $jsonFieldsValues[$index][$fieldDefinition->identifier][] = $relationFieldContent->id;
                                    $jsonFieldsValues[$index][$fieldDefinition->identifier.'_relation_list'][$relationFieldContent->id] = $relationFieldContent->contentInfo->name;
                                }

                                $jsonFieldsValues[$index]['main_'.$fieldDefinition->identifier] = reset($jsonFieldsValues[$index][$fieldDefinition->identifier]);
                            } else {
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = array();
                                $jsonFieldsValues[$index][$fieldDefinition->identifier.'_relation_list'] = array();
                                $jsonFieldsValues[$index]['main_'.$fieldDefinition->identifier] = '';
                            }
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezobjectrelation') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier)->destinationContentId;

                            if (!empty($fieldValue)) {
                                $relationFieldContent = $contentService->loadContent($fieldValue);
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = $relationFieldContent->id;
                                $jsonFieldsValues[$index][$fieldDefinition->identifier.'_relation'][$relationFieldContent->id] = $relationFieldContent->contentInfo->name;
                            } else {
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = '';
                                $jsonFieldsValues[$index][$fieldDefinition->identifier.'_relation'] = array();
                            }
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezstring') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $jsonFieldsValues[$index][$fieldDefinition->identifier] = $fieldValue->text;
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'eztext') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $jsonFieldsValues[$index][$fieldDefinition->identifier] = $fieldValue->text;
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezemail') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $jsonFieldsValues[$index][$fieldDefinition->identifier] = $fieldValue->email;
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezimage') {
                            if (array_key_exists('image_alias', $settings)) {
                                $imageField = $contentItem->getField($fieldDefinition->identifier);

                                foreach ($settings['image_alias'] as $variationName) {
                                    try {
                                        $variation = $this->imageVariationHandler->getVariation($imageField, $contentItem->getVersionInfo(), $variationName);
                                        $jsonFieldsValues[$index][$fieldDefinition->identifier.'_'.$variationName] = $variation->uri;
                                    } catch (\Exception $e) {
                                        continue;
                                    }
                                }
                            }

                            $extensions = array('.png', '.jpg');
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);

                            if ($fieldValue->uri) {
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = $fieldValue->uri;
                                $jsonFieldsValues[$index]['alt_'.$fieldDefinition->identifier] = str_replace($extensions, '', $fieldValue->fileName);
                            } else {
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = '';
                                $jsonFieldsValues[$index]['alt_'.$fieldDefinition->identifier] = '';
                            }
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezdatetime') {
                            $spanishDays = array('Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sábado');
                            $spanishMonths = array('Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre');

                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $timestamp = $fieldValue->value->getTimestamp();
                            $formatterDate = array(
                                'timestamp' => $timestamp,
                                'day' => date('d', $timestamp),
                                'name_day' => strtolower(date('l', $timestamp)),
                                'spanish_name_day' => strtolower($spanishDays[date('w', $timestamp)]),
                                'month' => date('m', $timestamp),
                                'name_month' => strtolower(date('F', $timestamp)),
                                'spanish_name_month' => strtolower($spanishMonths[date('n', $timestamp) - 1]),
                                'year' => date('Y', $timestamp),
                                'hour' => date('H', $timestamp),
                                'minutes' => date('i', $timestamp),
                            );
                            $jsonFieldsValues[$index][$fieldDefinition->identifier] = $timestamp;
                            $jsonFieldsValues[$index]['formatter_'.$fieldDefinition->identifier] = $formatterDate;
                            $jsonFieldsValues[$index]['year_'.$fieldDefinition->identifier] = date('Y', $timestamp);
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezxmltext') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $convertedHtml = $html5Converter->convert($fieldValue->xml);

                            if ($convertedHtml) {
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = $convertedHtml;
                            } else {
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = '';
                            }
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezinteger') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $jsonFieldsValues[$index][$fieldDefinition->identifier] = $fieldValue->value;
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezurl') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $jsonFieldsValues[$index][$fieldDefinition->identifier] = $fieldValue->link;
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezselection') {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $jsonFieldsValues[$index][$fieldDefinition->identifier] = reset($fieldValue->selection);
                        } elseif ($fieldDefinition->fieldTypeIdentifier == 'ezcountry') {
                            $fieldValue = reset($contentItem->getFieldValue($fieldDefinition->identifier)->countries);

                            if ($fieldValue) {
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = $fieldValue['Name'];
                            } else {
                                $jsonFieldsValues[$index][$fieldDefinition->identifier] = '';
                            }
                        } else {
                            $fieldValue = $contentItem->getFieldValue($fieldDefinition->identifier);
                            $jsonFieldsValues[$index][$fieldDefinition->identifier] = $fieldValue->value;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            if (isset($this->ccbLogger)) {
                $this->ccbLogger->error($e->getMessage());
            }
        }

        return $jsonFieldsValues;
    }

    /**
     * Returns array with promo image and content.
     *
     * @return array with promoimage and content, efauklts to 'image' and initial content
     */
    public function getPromoImage($content)
    {
        $promoImage = array(
            'imageContent' => $content,
            'imageFieldIdentifier' => 'promo_image',
        );

        try {
            if ($this->fieldHasContent($content, 'promo_image')) {
                $promoImage['imageFieldIdentifier'] = 'promo_image';
            } elseif ($this->fieldHasContent($content, 'event_picture')) {
                $promoImage['imageFieldIdentifier'] = 'event_picture';
            } elseif ($this->fieldHasContent($content, 'highlighted_image')) {
                $promoImage['imageFieldIdentifier'] = 'highlighted_image';
            } elseif ($this->fieldHasContent($content, 'image')) {
                $promoImage['imageFieldIdentifier'] = 'image';
            } elseif ($this->fieldHasContent($content, 'image_related')) {
                if ($this->validateContentType($content, 'image_related', 'ezobjectrelationlist')) {
                    $relatedContentIds = $content->getFieldValue('image_related')->destinationContentIds;

                    if (!empty($relatedContentIds)) {
                        $relatedItemContent = array();

                        foreach ($relatedContentIds as $index => $relatedContentId) {
                            $relatedContent = $this->repository->getContentService()->loadContent($relatedContentId);

                            if ($this->fieldHasContent($relatedContent, 'image')) {
                                $relatedItemContent[] = $relatedContent;
                            }
                        }

                        $promoImage['imageContent'] = $relatedItemContent;
                        $promoImage['imageFieldIdentifier'] = 'image';
                    }
                }
            } elseif ($this->fieldHasContent($content, 'photo')) {
                $promoImage['imageFieldIdentifier'] = 'photo';
            } elseif ($this->fieldHasContent($content, 'related_photo')) {
                $relatedContent = $this->repository->getContentService()->loadContent($content->getFieldValue('related_photo')->destinationContentId);

                if ($this->fieldHasContent($relatedContent, 'image')) {
                    $promoImage['imageContent'] = $relatedContent;
                    $promoImage['imageFieldIdentifier'] = 'image';
                }
            } elseif ($this->fieldHasContent($content, 'gallery')) {
                if ($this->validateContentType($content, 'gallery', 'ezobjectrelationlist')) {
                    $relatedContentIds = $content->getFieldValue('gallery')->destinationContentIds;

                    if (!empty($relatedContentIds)) {
                        $relatedItemContent = array();

                        foreach ($relatedContentIds as $index => $relatedContentId) {
                            $relatedContent = $this->repository->getContentService()->loadContent($relatedContentId);

                            if ($this->fieldHasContent($relatedContent, 'image')) {
                                $relatedItemContent[] = $relatedContent;
                            }
                        }

                        $promoImage['imageContent'] = $relatedItemContent;
                        $promoImage['imageFieldIdentifier'] = 'image';
                    }
                }
            }
        } catch (\Exception $e) {
            if (isset($this->ccbLogger)) {
                $this->ccbLogger->error($e->getMessage().' in '.$content->getFieldValue('image'));
            }
            // If an exception is caught, it is ignored, because we need to fallback to the default promo image regardless the error.
        }

        return $promoImage;
    }

    /**
     * Returns true if a Field is defined and has content.
     *
     * @param object \eZ\Publish\Core\Repository\Values\Content\Content $content         the Content
     * @param string                                                    $fieldIdentifier the Field identifier
     *
     * @return bool
     */
    public function fieldHasContent($content, $fieldIdentifier = null)
    {
        try {
            if (array_key_exists($fieldIdentifier, $content->fields)) {
                if ($this->fieldHelper->isFieldEmpty($content, $fieldIdentifier)) {
                    throw new \Exception("Field '".$fieldIdentifier."' is empty");
                } else {
                    return true;
                }
            } else {
                throw new \Exception("Field '".$fieldIdentifier."' not found");
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Returns true if a content type is the same.
     *
     * @param object \eZ\Publish\Core\Repository\Values\Content\Content $content             the Content
     * @param string                                                    $fieldIdentifier     the Field identifier
     * @param string                                                    $fieldTypeIdentifier the Field type identifier
     *
     * @return bool
     */
    public function validateContentType($content, $fieldIdentifier, $fieldTypeIdentifier)
    {
        try {
            $contentType = $this->repository->getContentTypeService()->loadContentType($content->contentInfo->contentTypeId);

            if ($contentType->getFieldDefinition($fieldIdentifier)->fieldTypeIdentifier == $fieldTypeIdentifier) {
                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }
}
