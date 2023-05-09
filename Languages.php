<?php

class Language
{
    private $client;

    function __construct()
    {
        $this->client = new JobconvoApiClient();
    }

    public function Create($pk, $data)
    {
        $result = $this->client->Send('/pt-br/api/companycandidate/' . $pk . '/languages/', $data);
        if ($result) {
            return $result;
        }

        return null;
    }

    public function Update($pk, $data)
    {
        $result = $this->client->Update('/pt-br/api/companycandidate/' . $pk . '/languages/' . $data['id'] . '/', $data);

        if ($result) {
            return $result;
        }

        return null;
    }

    public function Get($pk, $id)
    {
        return $this->client->Get("/pt-br/api/companycandidate/{$pk}/languages/{$id}");
    }

    public function GetByUser($user)
    {
        $result = $this->client->Get('/api/candidate?pk=' . $user->pk);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function GetByEmail($email)
    {
        $result = $this->client->Get('/api/candidate?candidate=' . $email);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function Delete($pk, $data)
    {
        $result = $this->client->Delete('/pt-br/api/companycandidate/' . $pk . '/languages/' . $data['id'] . '/');

        if ($result && isset($result['detail'])) {
            return true;
        }

        return false;
    }
}
