<?php

namespace App\Controller\Api;

use App\Entity\Structure;
use App\Facade\Badge\BadgeFacade;
use App\Facade\Structure\StructureFacade;
use App\Facade\Structure\StructureFiltersFacade;
use App\Facade\Structure\StructureReadFacade;
use App\Manager\StructureManager;
use App\Transformer\ResourceTransformer;
use App\Transformer\StructureTransformer;
use Bundles\ApiBundle\Annotation\Endpoint;
use Bundles\ApiBundle\Annotation\Facade;
use Bundles\ApiBundle\Base\BaseController;
use Bundles\ApiBundle\Contracts\FacadeInterface;
use Bundles\ApiBundle\Model\Facade\Http\HttpCreatedFacade;
use Bundles\ApiBundle\Model\Facade\QueryBuilderFacade;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Structures are independent Red Cross sections that manage volunteers and organize operations.
 *
 * @Route("/api/structure", name="api_structure_")
 */
class StructureController extends BaseController
{
    /**
     * @var StructureManager
     */
    private $structureManager;

    /**
     * @var StructureTransformer
     */
    private $structureTransformer;

    /**
     * @var ResourceTransformer
     */
    private $resourceTransformer;

    public function __construct(StructureManager $structureManager,
        StructureTransformer $structureTransformer,
        ResourceTransformer $resourceTransformer)
    {
        $this->structureManager     = $structureManager;
        $this->structureTransformer = $structureTransformer;
        $this->resourceTransformer  = $resourceTransformer;
    }

    /**
     * List all structures.
     *
     * @Endpoint(
     *   priority = 300,
     *   request  = @Facade(class     = StructureFiltersFacade::class),
     *   response = @Facade(class     = QueryBuilderFacade::class,
     *                      decorates = @Facade(class = StructureReadFacade::class))
     * )
     * @Route(name="records", methods={"GET"})
     */
    public function records(StructureFiltersFacade $filters) : FacadeInterface
    {
        $qb = $this->structureManager->searchQueryBuilder($filters->getCriteria(), $filters->isOnlyEnabled());

        return new QueryBuilderFacade($qb, $filters->getPage(), function (Structure $structure) {
            return $this->structureTransformer->expose($structure);
        });
    }

    /**
     * Create a new structure.
     *
     * @Endpoint(
     *   priority = 305,
     *   request  = @Facade(class     = StructureFacade::class),
     *   response = @Facade(class     = HttpCreatedFacade::class)
     * )
     * @Route(name="create", methods={"POST"})
     */
    public function create(StructureFacade $facade) : FacadeInterface
    {
        $structure = $this->structureTransformer->reconstruct($facade);

        $this->validate($structure, [
            new UniqueEntity(['platform', 'externalId']),
        ], ['create']);

        $this->structureManager->save($structure);

        return new HttpCreatedFacade();
    }
}