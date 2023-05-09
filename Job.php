<?php

class Job
{
    private $client;

    function __construct()
    {
        $this->client = new JobconvoApiClient();
    }

    public function Create($data)
    {
        // Ignoring for now
        return;

        $result = $this->client->Send('/api/job?pk=' . $data->pk, $data);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function Update($data)
    {
        // Ignoring for now, not implemented on JobConvo yet
        return;

        $result = $this->client->Update('/api/job?pk=' . $data->pk, $data);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function Get($params = null)
    {
        return $this->client->Get('/api/job' . ($params ? '?' . $params : ''));
    }

    public function GetById($id)
    {
        $result = $this->client->Get('/api/job?pk=' . $id);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function Delete($user)
    {
        $result = $this->client->Get('/api/job?pk=' . $user->pk);

        if ($result && isset($result['detail'])) {
            return true;
        }

        return false;
    }
}
