<?php

namespace WPBakeryDivi5Converter\Converter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface ConverterInterface {
    /**
     * @param array $node A tree node: ['id','tag','kind','atts','content','children','notes'].
     * @return array One block ['id','name','settings','elements'] or a list of blocks.
     */
    public function convert( array $node ): array;
}
