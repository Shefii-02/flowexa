<?php

namespace App\Modules\Flow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowNodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'flow_builder_id'   => $this->flow_builder_id,
            'parent_id'         => $this->parent_id,
            'title'             => $this->title,
            'message'           => $this->message,
            // ordered array of {type,content|url,caption,filename,lat,lng,name,address}
            'multi_messages'    => $this->multi_messages ?? [],
            'has_multi_messages'=> !empty($this->multi_messages) && count($this->multi_messages) > 0,
            'type'              => $this->type,
            'reply_id'          => $this->reply_id,
            'lead_category'     => $this->lead_category,
            'sort_order'        => $this->sort_order,
            'is_active'         => (bool) $this->is_active,
            'media_type'        => $this->media_type,
            'media_url'         => $this->media_url,
            'media_caption'     => $this->media_caption,
            'media_filename'    => $this->media_filename,
            'location'          => $this->when($this->media_type === 'location', [
                'lat'     => $this->location_lat,
                'lng'     => $this->location_lng,
                'name'    => $this->location_name,
                'address' => $this->location_address,
            ]),
            'children'          => FlowNodeResource::collection($this->whenLoaded('children')),
            'created_at'        => $this->created_at,
        ];
    }
}
