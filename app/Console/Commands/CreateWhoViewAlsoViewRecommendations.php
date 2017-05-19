<?php

namespace App\Console\Commands;

use App\Services\Gremlin\Generic;
use Brightzone\GremlinDriver\ServerException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CreateWhoViewAlsoViewRecommendations extends Command
{

    const LIMIT = 100;

    const LIMIT_GENERATED_RECOMMENDATIONS = 20;

    const QUERY_GET_PRODUCTS = "g.V().hasLabel('product').range(%d, %d).values()";

    const QUERY_RECOMMENDATIONS = "g.V().has('productId', %d).as('p').in('view')
        .out('view').barrier().where(out('belong').has('categoryId', %d)).barrier()
        .where(neq('p')).groupCount().by('productId').order(local).by(values, decr).limit(local, %d)";

    const QUERY_GET_CATEGORIES_BY_PRODUCT = "g.V().has('productId', %d).out('belong').dedup().values()";

    const CACHE_PREFIX_CATEGORY = 'vav_p_%s_c_%s';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recommendation:createWhoViewAlsoView';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create whoViewAlsoView recommendations';

    /**
     * @var Generic
     */
    protected $gremlin;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        set_time_limit(0);
        $this->comment(PHP_EOL. 'Start whoViewAlsoView recommendations' .PHP_EOL);

        $this->gremlin = new Generic();

        $limit = self::LIMIT;
        $offset = 0;
        $continue = true;

        do {
            try {
                $query = sprintf(self::QUERY_GET_PRODUCTS, $offset, $limit);
                $result = $this->gremlin->executeQuery($query);
                $this->comment(PHP_EOL. 'Processing ' . count($result) . ' products' .PHP_EOL);

                if (!count($result)) {
                    $continue = false;
                    continue;
                }

                $this->getRecommendationsForProduct($result);

                $limit += self::LIMIT;
                $offset += self::LIMIT;
            } catch (ServerException $e) {
                $continue = false;
                continue;
            }

        } while ($continue);
    }

    /**
     * @param $arrayProducts
     */
    protected function getRecommendationsForProduct($arrayProducts)
    {
        foreach ($arrayProducts as $idProduct) {
            $categories = $this->getCategoriesByProduct($idProduct);
            foreach ($categories as $idCategory) {
                $recommendations = $this->getRecommendationsByProductAndCategory($idProduct, $idCategory);
                Cache::forever(
                    sprintf(self::CACHE_PREFIX_CATEGORY, $idProduct, $idCategory),
                    $recommendations
                );
            }
        }
    }

    /**
     * @param $idProduct
     * @return mixed
     */
    protected function getCategoriesByProduct($idProduct)
    {
        try {
            $categoriesQuery = sprintf(self::QUERY_GET_CATEGORIES_BY_PRODUCT, $idProduct);
            return $this->gremlin->executeQuery($categoriesQuery);
        } catch (ServerException $e) {
            return [];
        }
    }

    /**
     * @param $idProduct
     * @param $idCategory
     * @return mixed
     */
    protected function getRecommendationsByProductAndCategory($idProduct, $idCategory)
    {
        $query = sprintf(self::QUERY_RECOMMENDATIONS, $idProduct, $idCategory, self::LIMIT_GENERATED_RECOMMENDATIONS);
        return $this->gremlin->executeQuery($query);
    }
}
